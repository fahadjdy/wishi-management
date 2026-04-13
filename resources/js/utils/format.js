const inrFormatter = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
});

const inrFormatterPaise = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
});

export function formatINR(value, withPaise = false) {
    const num = Number(value || 0);
    return (withPaise ? inrFormatterPaise : inrFormatter).format(num);
}

export function formatDate(value, opts = { day: '2-digit', month: 'short', year: 'numeric' }) {
    if (!value) return '—';
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-IN', opts);
}

export function formatDateTime(value) {
    if (!value) return '—';
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function relativeTime(value) {
    if (!value) return '';
    const d = value instanceof Date ? value : new Date(value);
    const diff = (d.getTime() - Date.now()) / 1000;
    const abs = Math.abs(diff);
    const rtf = new Intl.RelativeTimeFormat('en-IN', { numeric: 'auto' });
    if (abs < 60) return rtf.format(Math.round(diff), 'second');
    if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
    if (abs < 2592000) return rtf.format(Math.round(diff / 86400), 'day');
    if (abs < 31536000) return rtf.format(Math.round(diff / 2592000), 'month');
    return rtf.format(Math.round(diff / 31536000), 'year');
}

export const trustColor = {
    excellent: 'badge-success',
    good: 'badge-info',
    average: 'badge-warning',
    risky: 'badge-danger',
};

export const cycleStatusLabels = {
    pending: 'Pending',
    contribution_open: 'Contribution Open',
    bidding_open: 'Bidding Open',
    selection_pending: 'Selection Pending',
    completed: 'Completed',
};

export const wishiStatusLabels = {
    draft: 'Draft',
    planned: 'Planned',
    active: 'Active',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const memberStatusLabels = {
    pending: 'Pending Approval',
    approved: 'Approved',
    active: 'Active',
    removed: 'Removed',
    left: 'Left',
};
