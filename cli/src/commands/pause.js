import chalk from 'chalk'
import { getClient } from '../api.js'

export async function pause(id, resume = false) {
    try {
        const api = getClient()
        const action = resume ? 'resume' : 'pause'
        await api.post(`/monitors/${id}/${action}`)
        console.log(chalk.green(`Monitor ${id} ${resume ? 'resumed' : 'paused'}.`))
    } catch (e) {
        console.error(chalk.red(e.response?.data?.message || e.message))
    }
}
