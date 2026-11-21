export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined || Number.isNaN(bytes)) {
        return '—';
    }

    if (bytes === 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(
        Math.floor(Math.log(Math.abs(bytes)) / Math.log(1024)),
        units.length - 1,
    );
    const value = bytes / 1024 ** index;
    const formatted =
        index === 0
            ? String(Math.round(value))
            : value.toLocaleString('id-ID', {
                  maximumFractionDigits: value >= 100 ? 0 : 1,
              });

    return `${formatted} ${units[index]}`;
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return 'Belum ada';
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatPercent(part: number, total: number): number {
    if (total <= 0) {
        return 0;
    }

    return Math.min(100, Math.round((part / total) * 1000) / 10);
}
