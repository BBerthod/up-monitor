<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import { useAuth } from '@/Composables/useAuth'

const { user } = useAuth()

const profileForm = useForm({
    name: user.value?.name ?? '',
    email: user.value?.email ?? '',
})

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)

const updateProfile = () => {
    profileForm.put(route('profile.update'))
}

const updatePassword = () => {
    passwordForm.put(route('profile.password'), {
        onSuccess: () => passwordForm.reset(),
    })
}
</script>

<template>
    <Head title="My Profile" />

    <div class="max-w-2xl mx-auto space-y-8">
        <PageHeader title="My Profile" description="Manage your account settings" />

        <!-- Profile Information -->
        <form @submit.prevent="updateProfile" class="glass-intense p-8 space-y-6">
            <h2 class="text-lg font-semibold text-white">Profile Information</h2>

            <div>
                <label for="name" class="block text-sm font-medium text-slate-300 mb-1">Name</label>
                <input id="name" v-model="profileForm.name" type="text" class="form-input" required />
                <p v-if="profileForm.errors.name" class="mt-2 text-sm text-red-400">{{ profileForm.errors.name }}</p>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-300 mb-1">Email</label>
                <input id="email" v-model="profileForm.email" type="email" class="form-input" required />
                <p v-if="profileForm.errors.email" class="mt-2 text-sm text-red-400">{{ profileForm.errors.email }}</p>
            </div>

            <div class="flex justify-end">
                <button type="submit" :disabled="profileForm.processing" class="btn-primary py-3 px-6 disabled:opacity-50">
                    {{ profileForm.processing ? 'Saving...' : 'Save Changes' }}
                </button>
            </div>
        </form>

        <!-- Change Password -->
        <form @submit.prevent="updatePassword" class="glass-intense p-8 space-y-6">
            <h2 class="text-lg font-semibold text-white">Change Password</h2>

            <div>
                <label for="current_password" class="block text-sm font-medium text-slate-300 mb-1">Current Password</label>
                <div class="relative">
                    <input id="current_password" v-model="passwordForm.current_password" :type="showCurrentPassword ? 'text' : 'password'" class="form-input pr-10" required />
                    <button type="button" @click="showCurrentPassword = !showCurrentPassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-300 transition-colors">
                        <svg v-if="!showCurrentPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                    </button>
                </div>
                <p v-if="passwordForm.errors.current_password" class="mt-2 text-sm text-red-400">{{ passwordForm.errors.current_password }}</p>
            </div>

            <div>
                <label for="new_password" class="block text-sm font-medium text-slate-300 mb-1">New Password</label>
                <div class="relative">
                    <input id="new_password" v-model="passwordForm.password" :type="showNewPassword ? 'text' : 'password'" class="form-input pr-10" required />
                    <button type="button" @click="showNewPassword = !showNewPassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-300 transition-colors">
                        <svg v-if="!showNewPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                    </button>
                </div>
                <p v-if="passwordForm.errors.password" class="mt-2 text-sm text-red-400">{{ passwordForm.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-300 mb-1">Confirm New Password</label>
                <div class="relative">
                    <input id="password_confirmation" v-model="passwordForm.password_confirmation" :type="showConfirmPassword ? 'text' : 'password'" class="form-input pr-10" required />
                    <button type="button" @click="showConfirmPassword = !showConfirmPassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-300 transition-colors">
                        <svg v-if="!showConfirmPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                    </button>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" :disabled="passwordForm.processing" class="btn-primary py-3 px-6 disabled:opacity-50">
                    {{ passwordForm.processing ? 'Updating...' : 'Update Password' }}
                </button>
            </div>
        </form>
    </div>
</template>
