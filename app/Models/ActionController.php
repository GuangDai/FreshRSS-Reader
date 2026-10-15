<?php
declare(strict_types=1);

use FreshRSS\Utils\Http\SettingsEmbedPolicy;

abstract class FreshRSS_ActionController extends Minz_ActionController {

	/**
	 * @var FreshRSS_View
	 * @phpstan-ignore property.phpDocType
	 */
	protected $view;

	public function __construct(string $viewType = '') {
		Minz_Url::setControllerUrlTransformer(SettingsEmbedPolicy::amendUrl(...));
		parent::__construct($viewType === '' ? FreshRSS_View::class : $viewType);
	}

	/** @param array<string,string> $policies
	 * @return array<string,string>
	 */
	#[\Override]
	protected function amendCspPolicies(array $policies): array {
		return SettingsEmbedPolicy::framePolicy($policies, SettingsEmbedPolicy::active());
	}
}
