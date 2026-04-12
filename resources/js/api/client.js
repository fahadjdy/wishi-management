import axios from 'axios';

const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
});

let csrfPrimed = false;

export async function ensureCsrf() {
    if (csrfPrimed) return;
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
    csrfPrimed = true;
}

api.interceptors.request.use(async (config) => {
    const method = (config.method || 'get').toLowerCase();
    if (['post', 'put', 'patch', 'delete'].includes(method)) {
        await ensureCsrf();
    }
    return config;
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 419) {
            csrfPrimed = false;
        }
        return Promise.reject(error);
    },
);

export default api;
