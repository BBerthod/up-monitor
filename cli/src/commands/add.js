import chalk from 'chalk'
import { getClient } from '../api.js'

export async function add(url, options) {
    try {
        const api = getClient()
        const { data } = await api.post('/monitors', {
            name: options.name || url,
            url,
            method: 'GET',
            expected_status_code: 200,
            interval: parseInt(options.interval, 10),
        })
        console.log(chalk.green(`Monitor created: ${data.data.name} (ID: ${data.data.id})`))
    } catch (e) {
        console.error(chalk.red(e.response?.data?.message || e.message))
    }
}
