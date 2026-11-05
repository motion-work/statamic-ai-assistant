import axios from 'axios';

/**
 * POST to an AI endpoint with an AbortController the caller can use to cancel
 * (the modal's "Abbrechen" while generating).
 */
export function aiPost(url, data, controller) {
  return axios.post(url, data, { signal: controller?.signal });
}

export function isAiRequestCancelled(error) {
  return axios.isCancel(error) || error?.name === 'CanceledError' || error?.code === 'ERR_CANCELED';
}

/**
 * A message an editor can act on. Provider errors ("Infomaniak request failed
 * (400): …") and HTTP details go to the console for whoever debugs it; the
 * editor gets a plain sentence. Validation errors (422) and flagged provider
 * refusals (`ai_provider_error`, see AiProviderException::render) are already
 * readable and shown as they are.
 */
export function friendlyAiError(error, fallbackKey = 'The AI request failed. Please try again. If it keeps happening, tell your administrator.') {
  if (error?.response) {
    console.error('[AI assistant]', error.response.status, error.response.data);
  } else {
    console.error('[AI assistant]', error);
  }

  const status = error?.response?.status;

  if (status === 422) {
    const errors = error.response.data?.errors;
    const first = errors && Object.values(errors)[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }

  // Provider refusals (out of credits, budget used up, no model can serve the
  // request) arrive already translated and written for editors.
  const data = error?.response?.data;
  if (data?.ai_provider_error && typeof data.message === 'string' && data.message.trim() !== '') {
    return data.message;
  }

  if (status === 419) {
    return __('Your session has expired. Reload the page and try again.');
  }

  if (!error?.response || status === 504 || error?.code === 'ECONNABORTED') {
    return __('The AI did not respond in time. Please try again.');
  }

  return __(fallbackKey);
}
