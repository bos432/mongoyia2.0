import { spawn } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const VERSION = 'MONGOYIA_APP_H5_BUILD_WARNING_GOVERNANCE_V1'
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const envFiles = ['.env', '.env.local', '.env.development', '.env.production']

const offenders = envFiles.filter((name) => {
  const path = join(root, name)
  if (!existsSync(path)) {
    return false
  }
  return /^\s*NODE_ENV\s*=/m.test(readFileSync(path, 'utf8'))
})

if (offenders.length > 0) {
  console.error(`${VERSION}: unsupported NODE_ENV entries found in ${offenders.join(', ')}`)
  process.exit(1)
}

const viteBin = join(root, 'node_modules', 'vite', 'bin', 'vite.js')

const env = {
  ...process.env,
  VITE_CJS_IGNORE_WARNING: 'true'
}
delete env.NODE_ENV

const child = spawn(process.execPath, [viteBin, 'build'], {
  cwd: root,
  env,
  stdio: ['inherit', 'pipe', 'pipe']
})

const knownNotice = 'NODE_ENV=production is not supported'

function pipeFiltered(stream, target) {
  let pending = ''
  stream.on('data', (chunk) => {
    pending += chunk.toString()
    const lines = pending.split(/\r?\n/)
    pending = lines.pop() || ''
    for (const line of lines) {
      if (line.includes(knownNotice)) {
        continue
      }
      target.write(line + '\n')
    }
  })
  stream.on('end', () => {
    if (pending && !pending.includes(knownNotice)) {
      target.write(pending)
    }
  })
}

pipeFiltered(child.stdout, process.stdout)
pipeFiltered(child.stderr, process.stderr)

child.on('close', (code) => {
  if (code === 0) {
    console.log(`${VERSION}: build completed; Vite CJS warning suppressed and NODE_ENV .env guard passed.`)
  }
  process.exit(code || 0)
})
