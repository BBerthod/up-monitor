import chalk from 'chalk'
import { getClient } from '../api.js'

export async function status(id) {
    try {
        const api = getClient()

        if (!id) {
            const { data } = await api.get('/monitors')
            const monitors = data.data
            const up = monitors.filter(m => m.is_active && m.latest_check?.status === 'up').length
            const down = monitors.filter(m => m.is_active && m.latest_check?.status === 'down').length
            const paused = monitors.filter(m => !m.is_active).length

            console.log('')
            console.log(chalk.bold('  System Status'))
            console.log(`  ${chalk.green('●')} Up: ${up}  ${chalk.red('●')} Down: ${down}  ${chalk.gray('●')} Paused: ${paused}`)
            console.log('')
            return
        }

        const { data } = await api.get(`/monitors/${id}`)
        const m = data.data

        console.log('')
        console.log(chalk.bold(`  ${m.name}`))
        console.log(chalk.gray(`  ${m.url}`))
        console.log(`  Status: ${m.is_active ? (m.latest_check?.status === 'up' ? chalk.green('UP') : chalk.red('DOWN')) : chalk.gray('PAUSED')}`)
        console.log(`  Method: ${m.method}  Interval: ${m.interval}min`)
        if (m.latest_check) {
            console.log(`  Response: ${m.latest_check.response_time_ms}ms  Code: ${m.latest_check.status_code}`)
        }
        console.log('')
    } catch (e) {
        console.error(chalk.red(e.response?.data?.message || e.message))
    }
}
