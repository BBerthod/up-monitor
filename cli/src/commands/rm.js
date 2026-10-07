import chalk from 'chalk'
import { getClient } from '../api.js'

export async function rm(id) {
    try {
        const api = getClient()
        await api.delete(`/monitors/${id}`)
        console.log(chalk.green(`Monitor ${id} deleted.`))
    } catch (e) {
        console.error(chalk.red(e.response?.data?.message || e.message))
    }
}
