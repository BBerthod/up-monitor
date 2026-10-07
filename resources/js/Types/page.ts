export interface SiteScopeInfo {
    id: number
    name: string
    domain: string
}

export interface SiteScope {
    mode: 'all' | 'site' | 'unassigned'
    site: SiteScopeInfo | null
}

export interface User {
    id: number
    name: string
    email: string
    is_admin: boolean
    team_id: number
}

export interface Team {
    id: number
    name: string
    slug: string
}

export interface Flash {
    success?: string
    error?: string
    warning?: string
    message?: string
}

export interface Auth {
    user: User
    team: Team
}

declare module '@inertiajs/vue3' {
    interface PageProps {
        auth: Auth
        flash?: Flash
        errors?: Record<string, string>
        siteScope?: SiteScope
        navSites?: Array<{ id: number; name: string; domain: string }>
    }
}
