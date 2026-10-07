import chalk from 'chalk'
import { setToken } from '../api.js'

export function login(token) {
    setToken(token)
    console.log(chalk.green('Token saved successfully.'))
}
