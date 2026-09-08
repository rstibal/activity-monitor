$stdin = [Console]::In.ReadToEnd()
try {
    $hookInput = $stdin | ConvertFrom-Json
} catch {
    exit 0
}

$cmd = $hookInput.tool_input.command
if (-not $cmd) { exit 0 }

# Only touch phpcs/composer invocations that aren't already output-limited.
if (($cmd -match 'phpcs') -or ($cmd -match 'composer')) {
    if ($cmd -notmatch 'Select-Object' -and $cmd -notmatch 'Out-File' -and $cmd -notmatch '-Tail\b') {
        $newCmd = "$cmd 2>&1 | Select-Object -Last 200"
        $result = @{
            hookSpecificOutput = @{
                hookEventName      = 'PreToolUse'
                permissionDecision = 'allow'
                updatedInput       = @{ command = $newCmd }
            }
        }
        $result | ConvertTo-Json -Depth 10 -Compress
    }
}
