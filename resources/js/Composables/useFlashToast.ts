import { computed, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useToast } from 'primevue/usetoast'

interface FlashData {
    success?: string
    error?: string
    warning?: string
    message?: string
    link?: string
    linkText?: string
}

interface ToastData {
    link?: string
    linkText?: string
}

declare module 'primevue/usetoast' {
    interface ToastMessageOptions {
        data?: ToastData
    }
}

export function useFlashToast() {
    const toast = useToast()
    const page = usePage()

    const flash = computed(() => page.props.flash as FlashData)

    const showToast = (
        severity: 'success' | 'info' | 'warn' | 'error' | 'secondary' | 'contrast',
        summary: string,
        detail: string,
        link?: string,
        linkText?: string
    ) => {
        const hasLink = link && linkText

        toast.add({
            severity,
            summary,
            detail,
            life: hasLink ? 6000 : 4000,
            data: { link, linkText },
        })
    }

    watch(
        flash,
        (newFlash) => {
            if (!newFlash) return

            if (newFlash.success) {
                showToast('success', 'Success', newFlash.success, newFlash.link, newFlash.linkText)
            }

            if (newFlash.error) {
                showToast('error', 'Error', newFlash.error, newFlash.link, newFlash.linkText)
            }

            if (newFlash.warning) {
                showToast('warn', 'Warning', newFlash.warning, newFlash.link, newFlash.linkText)
            }

            if (newFlash.message) {
                showToast('info', 'Info', newFlash.message, newFlash.link, newFlash.linkText)
            }
        },
        { deep: true, immediate: true }
    )
}
