import chokidar from 'chokidar'

// Receive watch paths from parent process via stdin
let watchPaths = []
let watcher = null

process.stdin.on('data', (data) => {
    try {
        const message = JSON.parse(data.toString().trim())
        if (message.type === 'configure') {
            watchPaths = message.paths || []
            startWatching()
        }
    } catch {
        // Ignore non-JSON input
    }
})

function startWatching() {
    if (watchPaths.length === 0) return

    // Clean up existing watcher if reconfiguring
    if (watcher) {
        watcher.close()
    }

    watcher = chokidar.watch(watchPaths, {
        persistent: true,
        ignoreInitial: true,
        awaitWriteFinish: {
            stabilityThreshold: 150,
            pollInterval: 50,
        },
    })

    watcher.on('change', (filePath) => {
        const isGameLog = filePath.includes('Match_GameLog_') && filePath.endsWith('.dat')
        const type = isGameLog ? 'game_log_changed' : 'log_changed'

        process.stdout.write(JSON.stringify({ type, path: filePath }) + '\n')
    })

    watcher.on('add', (filePath) => {
        process.stdout.write(JSON.stringify({ type: 'file_added', path: filePath }) + '\n')
    })

    watcher.on('error', (error) => {
        process.stderr.write(JSON.stringify({ type: 'error', message: error.message }) + '\n')
    })
}
