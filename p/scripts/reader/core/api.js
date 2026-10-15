export class ReaderError extends Error {
	constructor(status, message) {
		super(message);
		this.status = status;
	}
}

export class ReaderAPI {
	constructor(csrf, { timeoutSeconds, base = './' }) {
		this.csrf = csrf;
		this.timeoutSeconds = timeoutSeconds;
		this.base = new URL(base, location.href);
		this.requests = new Set();
	}

	abortAll() { for (const controller of this.requests) controller.abort(); }

	async request(action, params = {}, { signal, body } = {}) {
		const url = new URL(this.base);
		url.search = new URLSearchParams({ c: 'reader', a: action, ...params });
		const controller = new AbortController();
		this.requests.add(controller);
		const abort = () => controller.abort(signal.reason);
		if (signal?.aborted) abort();
		else signal?.addEventListener('abort', abort, { once: true });
		const timeout = this.timeoutSeconds > 0 ? setTimeout(() => controller.abort(new ReaderError(408, 'timeout')), this.timeoutSeconds * 1000) : null;
		try {
			const response = await fetch(url, {
				method: body ? 'POST' : 'GET',
				credentials: 'same-origin',
				signal: controller.signal,
				headers: body ? { 'Content-Type': 'application/json' } : { Accept: 'application/json' },
				body: body ? JSON.stringify({ ...body, _csrf: this.csrf }) : undefined,
			});
			const data = await response.json().catch(() => null);
			if (!response.ok) {
				const error = new ReaderError(response.status, data?.error || 'request_failed');
				error.fields = data?.fields;
				error.details = data;
				throw error;
			}
			if (!data) throw new ReaderError(502, 'invalid_response');
			if (data.version !== 1) throw new ReaderError(502, 'invalid_response');
			return data;
		} catch (error) {
			if (controller.signal.aborted) throw controller.signal.reason;
			throw error;
		} finally {
			this.requests.delete(controller);
			clearTimeout(timeout);
			signal?.removeEventListener('abort', abort);
		}
	}
}
