import chalk from 'chalk'
import { getClient } from '../api.js'

export async function list() {
    try {
        const api = getClient()
        const { data } = await api.get('/monitors')
        const monitors = data.data

        if (!monitors.length) {
            console.log(chalk.yellow('No monitors found.'))
            return
        }

        console.log('')
        console.log(chalk.bold('  ID   Status   Name                           URL'))
        console.log(chalk.gray('  ─────────────────────────────────────────────────────────────'))

        for (const m of monitors) {
            const status = m.is_active
                ? (m.latest_check?.status === 'up' ? chalk.green('UP  ') : chalk.red('DOWN'))
                : chalk.gray('PAUSE')
            const name = m.name.padEnd(30).slice(0, 30)
            const url = m.url.slice(0, 40)
            console.log(`  ${String(m.id).padStart(4)}  ${status}  ${chalk.white(name)}  ${chalk.gray(url)}`)
        }
        console.log('')
    } catch (e) {
        console.error(chalk.red(e.response?.data?.message || e.message))
    }
}
