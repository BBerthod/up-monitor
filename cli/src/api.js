import axios from 'axios'
import Conf from 'conf'
import chalk from 'chalk'

const config = new Conf({ projectName: 'up-cli' })

export function getClient() {
    const token = config.get('token')
    const baseUrl = config.get('baseUrl', 'http://localhost:8000')

    if (!token) {
        console.error(chalk.red('Not authenticated. Run: up login <token>'))
        process.exit(1)
    }

    return axios.create({
        baseURL: `${baseUrl}/api`,
        headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
        },
    })
}

export function setToken(token) {
    config.set('token', token)
}

export function setBaseUrl(url) {
    config.set('baseUrl', url)
}
