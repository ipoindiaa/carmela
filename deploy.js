/**
 * Auto Deploy Script
 * Usage:
 *   DEPLOY_SSH_KEY='~/.ssh/hostinger_key' node deploy.js "commit message"
 *   DEPLOY_PASSWORD='...' DEPLOY_DB_PASS='...' node deploy.js "commit message"
 *
 * What it does:
 * 1. Stages all local changes and commits them
 * 2. Pushes the current branch to origin/main
 * 3. Connects to Hostinger shared hosting over SSH
 * 4. Clones the repo on first deploy or hard-resets to origin/main on later deploys
 * 5. Optionally writes config/database.local.php when DEPLOY_DB_PASS is provided
 */

const { execFileSync } = require('child_process');

const SERVER = {
  host: process.env.DEPLOY_HOST || '147.93.109.162',
  username: process.env.DEPLOY_USER || 'u892049228',
  port: process.env.DEPLOY_PORT || '65002',
  password: process.env.DEPLOY_PASSWORD || '',
  sshKey: process.env.DEPLOY_SSH_KEY || '',
};

const REMOTE_PATH =
  process.env.DEPLOY_PATH ||
  '/home/u892049228/domains/tirangacarworld.com/public_html';
const REMOTE_REPO = process.env.DEPLOY_REPO || 'git@github.com:ipoindiaa/carmela.git';
const REMOTE_GITHUB_KEY = process.env.DEPLOY_GITHUB_KEY || '~/.ssh/github_carmela_deploy';
const LOCAL_GIT_SSH_COMMAND =
  process.env.LOCAL_GIT_SSH_COMMAND ||
  'ssh -F /dev/null -i ~/.ssh/carmela_github_push -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new';
const REMOTE_DB = {
  host: process.env.DEPLOY_DB_HOST || 'localhost',
  name: process.env.DEPLOY_DB_NAME || 'u892049228_tirangamaindb',
  user: process.env.DEPLOY_DB_USER || 'u892049228_tirangamaindb',
  pass: process.env.DEPLOY_DB_PASS || '',
  charset: process.env.DEPLOY_DB_CHARSET || 'utf8mb4',
};

function run(command, args, options = {}) {
  execFileSync(command, args, {
    stdio: 'inherit',
    ...options,
  });
}

function git(args, extraEnv = {}) {
  run('git', args, {
    env: {
      ...process.env,
      GIT_SSH_COMMAND: LOCAL_GIT_SSH_COMMAND,
      ...extraEnv,
    },
  });
}

function escapeForExpect(value) {
  return String(value)
    .replace(/\\/g, '\\\\')
    .replace(/"/g, '\\"')
    .replace(/\[/g, '\\[')
    .replace(/\]/g, '\\]')
    .replace(/\$/g, '\\$');
}

function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function buildRemoteDatabaseConfigCommand() {
  if (!REMOTE_DB.pass) {
    return '# DEPLOY_DB_PASS not provided; skipping database.local.php write';
  }

  const config = `<?php

return [
    'host' => '${phpString(REMOTE_DB.host)}',
    'name' => '${phpString(REMOTE_DB.name)}',
    'user' => '${phpString(REMOTE_DB.user)}',
    'pass' => '${phpString(REMOTE_DB.pass)}',
    'charset' => '${phpString(REMOTE_DB.charset)}',
];
`;

  return [
    'mkdir -p config',
    "cat > config/database.local.php <<'PHP'",
    config,
    'PHP',
    'chmod 600 config/database.local.php',
  ].join('\n');
}

function sshExpect(remoteCommand) {
  if (!SERVER.password) {
    throw new Error('DEPLOY_PASSWORD is required.');
  }
  const expectScript = `
set timeout 120
spawn ssh -F /dev/null -o StrictHostKeyChecking=no -p ${escapeForExpect(SERVER.port)} ${escapeForExpect(SERVER.username)}@${escapeForExpect(SERVER.host)}
expect {
  -re ".*yes/no.*" { send "yes\\r"; exp_continue }
  -re ".*password:.*" { send "${escapeForExpect(SERVER.password)}\\r" }
}
expect -re {[#$>] $}
send "${escapeForExpect(remoteCommand)}\\r"
expect -re {[#$>] $}
send "exit\\r"
expect eof
`;

  run('expect', ['-c', expectScript]);
}

function sshDeploy(remoteCommand) {
  if (SERVER.sshKey) {
    run('ssh', [
      '-F', '/dev/null',
      '-i', SERVER.sshKey,
      '-p', SERVER.port,
      '-o', 'IdentitiesOnly=yes',
      '-o', 'StrictHostKeyChecking=accept-new',
      `${SERVER.username}@${SERVER.host}`,
      remoteCommand,
    ]);
    return;
  }

  sshExpect(remoteCommand);
}

function buildRemoteDeployCommand() {
  if (!REMOTE_PATH) {
    throw new Error('DEPLOY_PATH is required.');
  }

  const remoteGitSsh = `ssh -F /dev/null -i ${REMOTE_GITHUB_KEY} -o StrictHostKeyChecking=no`;
  return [
    'set -e',
    `mkdir -p "${REMOTE_PATH}"`,
    `cd "${REMOTE_PATH}"`,
    'if [ -d .git ]; then',
    `  GIT_SSH_COMMAND='${remoteGitSsh}' git fetch origin main`,
    '  git reset --hard origin/main',
    'else',
    '  if [ -n "$(ls -A . 2>/dev/null)" ]; then',
    '    backup_dir="../deployment_backup_$(date +%Y%m%d_%H%M%S)"',
    '    mkdir -p "$backup_dir"',
    '    for item in .* *; do',
    '      if [ "$item" = "." ] || [ "$item" = ".." ]; then continue; fi',
    '      if [ -e "$item" ]; then mv "$item" "$backup_dir"/; fi',
    '    done',
    '  fi',
    `  GIT_SSH_COMMAND='${remoteGitSsh}' git clone "${REMOTE_REPO}" .`,
    'fi',
    buildRemoteDatabaseConfigCommand(),
  ].join('\n');
}

function deploy() {
  const commitMsg =
    process.argv.slice(2).join(' ') ||
    `Deploy ${new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' })}`;

  console.log('\nStep 1: Staging and committing...');
  git(['add', '.']);
  try {
    git(['commit', '-m', commitMsg]);
  } catch (error) {
    console.log('Nothing new to commit, continuing to push/deploy.');
  }

  console.log('\nStep 2: Pushing to GitHub...');
  git(['push', '-u', 'origin', 'main']);

  console.log('\nStep 3: Deploying on Hostinger...');
  sshDeploy(buildRemoteDeployCommand());
  console.log('\nDeploy complete.\n');
}

try {
  deploy();
} catch (error) {
  console.error('\nDeploy failed:', error.message);
  process.exit(1);
}
