import { useSyncExternalStore } from 'react';

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';
export type DashboardTheme = 'kaca' | 'minimal';

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly dashboardTheme: DashboardTheme;
    readonly updateAppearance: (mode: Appearance) => void;
    readonly updateDashboardTheme: (theme: DashboardTheme) => void;
};

const listeners = new Set<() => void>();
let currentAppearance: Appearance = 'system';
let currentDashboardTheme: DashboardTheme = 'kaca';

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredAppearance = (): Appearance => {
    if (typeof window === 'undefined') {
        return 'system';
    }

    return (localStorage.getItem('appearance') as Appearance) || 'system';
};

const getStoredDashboardTheme = (): DashboardTheme => {
    if (typeof window === 'undefined') {
        return 'kaca';
    }

    const stored = localStorage.getItem('dashboard_theme');
    return stored === 'minimal' ? 'minimal' : 'kaca';
};

const isDarkMode = (appearance: Appearance): boolean => {
    return appearance === 'dark' || (appearance === 'system' && prefersDark());
};

const applyTheme = (
    appearance: Appearance,
    dashboardTheme: DashboardTheme,
): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = isDarkMode(appearance);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', dashboardTheme);
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const mediaQuery = (): MediaQueryList | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const handleSystemThemeChange = (): void =>
    applyTheme(currentAppearance, currentDashboardTheme);

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!localStorage.getItem('appearance')) {
        localStorage.setItem('appearance', 'system');
        setCookie('appearance', 'system');
    }

    if (!localStorage.getItem('dashboard_theme')) {
        localStorage.setItem('dashboard_theme', 'kaca');
        setCookie('dashboard_theme', 'kaca');
    }

    currentAppearance = getStoredAppearance();
    currentDashboardTheme = getStoredDashboardTheme();
    applyTheme(currentAppearance, currentDashboardTheme);

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'system',
    );

    const dashboardTheme: DashboardTheme = useSyncExternalStore(
        subscribe,
        () => currentDashboardTheme,
        () => 'kaca',
    );

    const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
        ? 'dark'
        : 'light';

    const updateAppearance = (mode: Appearance): void => {
        currentAppearance = mode;
        localStorage.setItem('appearance', mode);
        setCookie('appearance', mode);
        applyTheme(mode, currentDashboardTheme);
        notify();
    };

    const updateDashboardTheme = (theme: DashboardTheme): void => {
        currentDashboardTheme = theme;
        localStorage.setItem('dashboard_theme', theme);
        setCookie('dashboard_theme', theme);
        applyTheme(currentAppearance, theme);
        notify();
    };

    return {
        appearance,
        resolvedAppearance,
        dashboardTheme,
        updateAppearance,
        updateDashboardTheme,
    } as const;
}
