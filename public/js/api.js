// Global fetch wrapper for the Jayfoods API.
// All backend hydration goes through this single module.

const API_BASE = '/api/v1';

async function apiFetch(path, options = {}) {
  const response = await fetch(API_BASE + path, {
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers || {}),
    },
    ...options,
  });

  let payload = null;
  try {
    payload = await response.json();
  } catch (_) {
    // Non-JSON / empty body — leave payload null.
  }

  if (!response.ok) {
    const error = new Error((payload && payload.error) || `Request failed (${response.status})`);
    error.status = response.status;
    error.fields = (payload && payload.fields) || {};
    throw error;
  }

  return payload;
}

// eslint-disable-next-line no-unused-vars
const api = {
  getProducts: () => apiFetch('/products'),
  getSiteContent: () => apiFetch('/site-content'),
  createOrder: (order) => apiFetch('/orders', { method: 'POST', body: JSON.stringify(order) }),
  getOrder: (reference) => apiFetch('/orders/' + encodeURIComponent(reference)),
  startPayment: (reference) => apiFetch('/orders/' + encodeURIComponent(reference) + '/pay', { method: 'POST' }),
  verifyPayment: (reference) => apiFetch('/payments/verify/' + encodeURIComponent(reference)),
  sendMessage: (message) => apiFetch('/messages', { method: 'POST', body: JSON.stringify(message) }),
};
