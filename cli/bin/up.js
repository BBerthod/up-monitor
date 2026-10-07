#!/usr/bin/env node

import { program } from 'commander'
import { login } from '../src/commands/login.js'
import { add } from '../src/commands/add.js'
import { list } from '../src/commands/list.js'
import { status } from '../src/commands/status.js'
import { rm } from '../src/commands/rm.js'
import { pause } from '../src/commands/pause.js'

program
    .name('up')
    .description('CLI for Up - uptime monitoring')
    .version('0.1.0')

program
    .command('login <token>')
    .description('Store API token for authentication')
    .action(login)

program
    .command('add <url>')
    .description('Create a new monitor')
    .option('-n, --name <name>', 'Monitor name')
    .option('-i, --interval <minutes>', 'Check interval in minutes', '5')
    .action(add)

program
    .command('list')
    .description('List all monitors')
    .action(list)

program
    .command('status [id]')
    .description('Show monitor status')
    .action(status)

program
    .command('rm <id>')
    .description('Delete a monitor')
    .action(rm)

program
    .command('pause <id>')
    .description('Pause a monitor')
    .action(pause)

program
    .command('resume <id>')
    .description('Resume a paused monitor')
    .action((id) => pause(id, true))

program.parse()
