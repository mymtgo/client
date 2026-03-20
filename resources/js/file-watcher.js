import chokidar from 'chokidar'
import { basename } from 'node:path'

// Watch paths are passed as command-line arguments
const watchPaths = process.argv.slice(2).filter(Boolean)

if (watchPaths.length === 0) {
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

watcher.on('change', (filePath) => {
    const type = classifyFile(filePath)
    if (type) {
        process.stdout.write(JSON.stringify({ type, path: filePath }) + '\n')
    }
})

watcher.on('add', (filePath) => {
    const type = classifyFile(filePath)
    if (type) {
        process.stdout.write(JSON.stringify({ type: 'file_added', path: filePath }) + '\n')
    }
})

watcher.on('error', (error) => {
    process.stderr.write(JSON.stringify({ type: 'error', message: error.message }) + '\n')
})
