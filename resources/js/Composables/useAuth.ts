import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import type { User, Team } from '@/Types/page'

export function useAuth() {
    const page = usePage()

    const user = computed<User | undefined>(() => page.props.auth?.user)
    const team = computed<Team | undefined>(() => page.props.auth?.team)
    const isAdmin = computed<boolean>(() => page.props.auth?.user?.is_admin ?? false)

    return { user, team, isAdmin }
}
