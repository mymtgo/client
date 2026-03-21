import chokidar from 'chokidar'
import { appendFileSync, mkdirSync } from 'node:fs'
import { basename, join } from 'node:path'

// Watch paths are passed as command-line arguments
const watchPaths = process.argv.slice(2).filter(Boolean)

// Log to a file so we can see what's happening independent of NativePHP IPC
const logDir = join(process.cwd(), 'storage', 'logs')
const logFile = join(logDir, 'watcher.log')

function log(message, data = {}) {
    const ts = new Date().toISOString()
    const line = `[${ts}] ${message} ${JSON.stringify(data)}\n`
    try {
        appendFileSync(logFile, line)
    } catch {
        // Best effort
    }
}

log('watcher:started', { watchPaths, pid: process.pid, argv: process.argv })

if (watchPaths.length === 0) {
    log('watcher:error', { message: 'No watch paths provided' })
    process.stderr.write(JSON.stringify({ type: 'error', message: 'No watch paths provided' }) + '\n')
    process.exit(1)
}

function classifyFile(filePath) {
    const name = basename(filePath)

    if (name === 'mtgo.log') return 'log_changed'
    if (name.startsWith('Match_GameLog_') && name.endsWith('.dat')) return 'game_log_changed'

    return null
}

const watcher = chokidar.watch(watchPaths, {
    persistent: true,
    ignoreInitial: true,
    awaitWriteFinish: {
        stabilityThreshold: 150,
        pollInterval: 50,
    },
})

watcher.on('ready', () => {
    log('watcher:ready', { watched: watcher.getWatched() })
})

watcher.on('change', (filePath) => {
    const type = classifyFile(filePath)
    log('watcher:change', { filePath, type: type ?? 'ignored' })

    if (type) {
        const msg = JSON.stringify({ type, path: filePath }) + '\n'
        log('watcher:stdout', { msg: msg.trim() })
        process.stdout.write(msg)
    }
})

watcher.on('add', (filePath) => {
    const type = classifyFile(filePath)
    log('watcher:add', { filePath, type: type ?? 'ignored' })

    if (type) {
        const msg = JSON.stringify({ type: 'file_added', path: filePath }) + '\n'
        log('watcher:stdout', { msg: msg.trim() })
        process.stdout.write(msg)
    }
})

watcher.on('error', (error) => {
    log('watcher:error', { message: error.message })
    process.stderr.write(JSON.stringify({ type: 'error', message: error.message }) + '\n')
})
