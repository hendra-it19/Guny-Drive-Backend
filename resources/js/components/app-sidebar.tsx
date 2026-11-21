import { Link, usePage } from '@inertiajs/react';
import { Activity, LayoutGrid, Shield, UsersRound } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as monitoringIndex } from '@/routes/monitoring';
import { edit as securityEdit } from '@/routes/security';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Ringkasan & Resource',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Pengguna & Storage',
        href: '/users',
        icon: UsersRound,
    },
    {
        title: 'API & Monitoring',
        href: monitoringIndex(),
        icon: Activity,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Keamanan & Privat',
        href: securityEdit(),
        icon: Shield,
    },
];

export function AppSidebar() {
    const admin = Boolean(
        (usePage().props.auth?.user as { is_admin?: boolean } | undefined)
            ?.is_admin,
    );
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={admin ? mainNavItems : []} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
