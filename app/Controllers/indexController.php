<?php
declare(strict_types=1);

/**
 * This class handles main actions of FreshRSS.
 */
class FreshRSS_index_Controller extends FreshRSS_ActionController {

	#[\Override]
	public function firstAction(): void {
		$this->view->html_url = Minz_Url::display(['c' => 'index', 'a' => 'index'], 'html', 'root');
	}

	/** The home page always opens the reading interface, independently of legacy view preferences. */
	public function indexAction(): void {
		Minz_Request::forward(['c' => 'reader', 'a' => 'index']);
	}

	/** Retain bookmarked reading URLs while serving the same home interface. */
	public function normalAction(): void {
		$this->indexAction();
	}

	public function readerAction(): void {
		$this->indexAction();
	}

	public function globalAction(): void {
		$this->indexAction();
	}

	/**
	 * This action displays the RSS feed of FreshRSS.
	 * @deprecated See user query RSS sharing instead
	 */
	public function rssAction(): void {
		// Check if user has access.
		if (!FreshRSS_Auth::hasAccess() && !FreshRSS_Auth::allowAnonymous() && !Minz_Request::tokenIsOk()) {
			Minz_Error::error(403, redirect: false);
			return;
		}

		try {
			FreshRSS_Context::updateUsingRequest(false);
		} catch (FreshRSS_Context_Exception $e) {
			Minz_Error::error(404);
		}

		try {
			$this->view->entries = FreshRSS_index_Controller::listEntriesByContext();
			if (!$this->view->entries->valid()) {	// Init the generator to catch potential exceptions
				$this->view->entries = new EmptyIterator();
			}
		} catch (FreshRSS_EntriesGetter_Exception $e) {
			Minz_Log::notice($e->getMessage());
			Minz_Error::error(404);
		}

		$this->view->html_url = Minz_Url::display('', 'html', true);
		$this->view->rss_title = FreshRSS_Context::$name . ' | ' . FreshRSS_View::title();

		$queryString = $_SERVER['QUERY_STRING'] ?? '';
		$this->view->rss_url = htmlspecialchars(
			PUBLIC_TO_INDEX_PATH . '/' . ($queryString === '' || !is_string($queryString) ? '' : '?' . $queryString), ENT_COMPAT, 'UTF-8');

		// No layout for RSS output.
		$this->view->_layout(null);
		header('Content-Type: application/rss+xml; charset=utf-8');
	}

	public function opmlAction(): void {
		// Check if user has access.
		if (!FreshRSS_Auth::hasAccess() && !FreshRSS_Auth::allowAnonymous() && !Minz_Request::tokenIsOk()) {
			Minz_Error::error(403, redirect: false);
			return;
		}

		try {
			FreshRSS_Context::updateUsingRequest(false);
		} catch (FreshRSS_Context_Exception) {
			Minz_Error::error(404);
		}

		$get = FreshRSS_Context::currentGet(true);
		$type = (string)$get[0];
		$id = (int)$get[1];

		$this->view->excludeMutedFeeds = $type !== 'f';	// Exclude muted feeds except when we focus on a feed

		switch ($type) {
			case 'a':	// All PRIORITY_MAIN_STREAM
			case 'A':	// All except PRIORITY_HIDDEN
			case 'Z':	// All including PRIORITY_HIDDEN
				$this->view->categories = FreshRSS_Context::categories();
				break;
			case 'c':	// Category
				$cat = FreshRSS_Context::categories()[$id] ?? null;
				if ($cat == null) {
					Minz_Error::error(404);
					return;
				}
				$this->view->categories = [$cat->id() => $cat];
				break;
			case 'f':	// Feed
				// We most likely already have the feed object in cache
				$feed = FreshRSS_Category::findFeed(FreshRSS_Context::categories(), $id);
				if ($feed === null) {
					$feedDAO = FreshRSS_Factory::createFeedDao();
					$feed = $feedDAO->searchById($id);
					if ($feed == null) {
						Minz_Error::error(404);
						return;
					}
				}
				$this->view->feeds = [$feed->id() => $feed];
				break;
			default:
				Minz_Error::error(404);
				return;
		}

		// No layout for OPML output.
		$this->view->_layout(null);
		header('Content-Type: application/xml; charset=utf-8');
	}

	/**
	 * This method returns a list of entries based on the Context object.
	 * @return Generator<FreshRSS_Entry>
	 * @throws FreshRSS_EntriesGetter_Exception
	 */
	public static function listEntriesByContext(): Generator {
		$entryDAO = FreshRSS_Factory::createEntryDao();

		$get = FreshRSS_Context::currentGet(true);
		if (is_array($get)) {
			$type = $get[0];
			$id = (int)($get[1]);
		} else {
			$type = $get;
			$id = 0;
		}

		$id_min = '0';
		if (FreshRSS_Context::$sinceHours > 0) {
			$id_min = (time() - (FreshRSS_Context::$sinceHours * 3600)) . '000000';
		}

		$continuation_values = [];
		if (FreshRSS_Context::$continuation_id !== '0') {
			if (in_array(FreshRSS_Context::$sort, ['c.name', 'date', 'f.name', 'link', 'title', 'lastUserModified', 'length'], true)) {
				$pagingEntry = $entryDAO->searchById(FreshRSS_Context::$continuation_id);

				if ($pagingEntry !== null && in_array(FreshRSS_Context::$sort, ['c.name', 'f.name'], true)) {
					// We most likely already have the feed object in cache
					$feed = FreshRSS_Category::findFeed(FreshRSS_Context::categories(), $pagingEntry->feedId());
					if ($feed !== null) {
						$pagingEntry->_feed($feed);
					}
				}

				$continuation_values[] = $pagingEntry === null ? 0 : match (FreshRSS_Context::$sort) {
					'c.name' => $pagingEntry->feed()?->categoryId() === FreshRSS_CategoryDAO::DEFAULTCATEGORYID ?
						FreshRSS_CategoryDAO::DEFAULT_CATEGORY_NAME : $pagingEntry->feed()?->category()?->name() ?? '',
					'date' => $pagingEntry->date(raw: true),
					'f.name' => $pagingEntry->feed()?->name(raw: true) ?? '',
					'link' => $pagingEntry->link(raw: true),
					'title' => $pagingEntry->title(),
					'lastUserModified' => $pagingEntry->lastUserModified() ?? 0,
					'length' => $pagingEntry->sqlContentLength() ?? 0,
				};
				if (FreshRSS_Context::$sort === 'c.name') {
					// Internal secondary sort criterion for category name
					$continuation_values[] = $pagingEntry?->feed()?->name(raw: true) ?? '';
				}
				if (in_array(FreshRSS_Context::$sort, ['c.name', 'f.name'], true)) {
					// User secondary sort criterion
					$continuation_values[] = $pagingEntry === null ? 0 : match (FreshRSS_Context::$secondary_sort) {
						'id' => $pagingEntry->id(),
						'date' => $pagingEntry->date(raw: true),
						'link' => $pagingEntry->link(raw: true),
						'title' => $pagingEntry->title(),
					};
				}
			} elseif (FreshRSS_Context::$sort === 'rand') {
				FreshRSS_Context::$continuation_id = '0';
			}
		}

		yield from $entryDAO->listWhere(
			$type, $id, FreshRSS_Context::$state, FreshRSS_Context::$search,
			id_min: $id_min, id_max: FreshRSS_Context::$id_max, sort: FreshRSS_Context::$sort, order: FreshRSS_Context::$order,
			continuation_id: FreshRSS_Context::$continuation_id, continuation_values: $continuation_values,
			limit: FreshRSS_Context::$number, offset: FreshRSS_Context::$offset,
			secondary_sort: FreshRSS_Context::$secondary_sort, secondary_sort_order: FreshRSS_Context::$secondary_sort_order);
	}

	/**
	 * This action displays the about page of FreshRSS.
	 */
	public function aboutAction(): void {
		FreshRSS_View::prependTitle(_t('index.about.title') . ' · ');
	}

	/**
	 * This action displays the EULA/TOS (Terms of Service) page of FreshRSS.
	 * This page is enabled only if admin created a data/tos.html file.
	 * The content of the page is the content of data/tos.html.
	 * It returns 404 if there is no EULA/TOS.
	 */
	public function tosAction(): void {
		$terms_of_service = file_get_contents(TOS_FILENAME);
		if ($terms_of_service === false) {
			Minz_Error::error(404);
			return;
		}

		$this->view->terms_of_service = $terms_of_service;
		$this->view->can_register = !FreshRSS_user_Controller::max_registrations_reached();
		FreshRSS_View::prependTitle(_t('index.tos.title') . ' · ');
	}

	/**
	 * This action displays logs of FreshRSS for the current user.
	 */
	public function logsAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}

		FreshRSS_View::prependTitle(_t('index.log.title') . ' · ');

		if (Minz_Request::isPost()) {
			FreshRSS_LogDAO::truncate();
		}

		$logs = FreshRSS_LogDAO::lines();	//TODO: ask only the necessary lines
		$search = trim(Minz_Request::paramString('search', plaintext: true));
		if ($search !== '') {
			$logs = array_values(array_filter($logs, static fn(FreshRSS_Log $log): bool =>
				stripos($log->level(), $search) !== false ||
				stripos($log->date(), $search) !== false ||
				stripos($log->info(), $search) !== false));
		}
		$this->view->logSearch = $search;

		//gestion pagination
		$page = Minz_Request::paramInt('page') ?: 1;
		$this->view->logsPaginator = new Minz_Paginator($logs);
		$this->view->logsPaginator->_nbItemsPerPage(50);
		$this->view->logsPaginator->_currentPage($page);
	}
}
