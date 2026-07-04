param(
    [string]$BaseUrl = "http://oxyconvo6.localhost/",
    [string]$PostId,
    [string]$OpenUrl,
    [string]$ReturnUrl,
    [switch]$SkipHttp,
    [switch]$SkipDocker
)

$ErrorActionPreference = "Stop"

$checks = @()
$curlPath = "C:\Windows\System32\curl.exe"
$oxygenRoot = "D:\WordPress\Html to Oxygen\oxygen"
$workspaceRoot = "D:\WordPress\Html to Oxygen\oxygen-html-converter-dev"
$dockerSyncScriptPath = Join-Path $workspaceRoot "plugins\core\tests\live\sync-docker-plugin.cjs"

function Add-Check {
    param(
        [string]$Name,
        [string]$Status,
        [string]$Detail
    )

    $script:checks += [pscustomobject]@{
        name = $Name
        status = $Status
        detail = $Detail
    }
}

function Build-DocumentBuilderUrl {
    param(
        [uri]$Base,
        [string]$Id
    )

    $builder = [System.UriBuilder]::new($Base.AbsoluteUri)
    $builder.Query = New-QueryString @{
        oxygen = "builder"
        id = [string]$Id
    }
    return $builder.Uri.AbsoluteUri
}

function Build-BrowseModeUrl {
    param(
        [uri]$Base,
        [string]$Open,
        [string]$Return
    )

    $params = [ordered]@{
        oxygen = "builder"
        mode = "browse"
    }

    if ($Open) {
        $params["browseModeOpenUrl"] = [uri]::EscapeDataString($Open)
    }

    if ($Return) {
        $params["returnUrl"] = [uri]::EscapeDataString($Return)
    }

    $builder = [System.UriBuilder]::new($Base.AbsoluteUri)
    $builder.Query = New-QueryString $params
    return $builder.Uri.AbsoluteUri
}

function New-QueryString {
    param(
        [hashtable]$Params
    )

    $pairs = foreach ($entry in $Params.GetEnumerator()) {
        if ($null -eq $entry.Value -or $entry.Value -eq "") {
            continue
        }

        "{0}={1}" -f [uri]::EscapeDataString([string]$entry.Key), [uri]::EscapeDataString([string]$entry.Value)
    }

    return ($pairs -join "&")
}

function Add-BuilderDistChecks {
    param(
        [string]$OxygenRootPath
    )

    $manifestPath = Join-Path $OxygenRootPath "builder\dist\manifest.json"

    if (-not (Test-Path $manifestPath)) {
        Add-Check -Name "builder-dist" -Status "missing" -Detail $manifestPath
        return
    }

    Add-Check -Name "builder-dist" -Status "ok" -Detail $manifestPath

    try {
        $manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
        $appBundle = $manifest."app.js"
        $appHtml = $manifest."app.html"

        if ($appBundle) {
            Add-Check -Name "builder-manifest" -Status "ok" -Detail "app.js=$appBundle"
        } else {
            Add-Check -Name "builder-manifest" -Status "missing" -Detail "app.js entry absent from $manifestPath"
        }

        if ($appHtml) {
            Add-Check -Name "builder-manifest" -Status "ok" -Detail "app.html=$appHtml"
        } else {
            Add-Check -Name "builder-manifest" -Status "missing" -Detail "app.html entry absent from $manifestPath"
        }
    } catch {
        Add-Check -Name "builder-manifest" -Status "error" -Detail $_.Exception.Message
    }

    $appBundlePaths = Get-ChildItem -Path (Join-Path $OxygenRootPath "builder\dist\js") -Filter "app*.js" -File -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty FullName

    if ($appBundlePaths) {
        Add-Check -Name "builder-bundle" -Status "ok" -Detail ($appBundlePaths -join ", ")
    } else {
        Add-Check -Name "builder-bundle" -Status "missing" -Detail "No app*.js files under $(Join-Path $OxygenRootPath 'builder\dist\js')"
    }
}

function Add-IntegrationAssetChecks {
    param(
        [string[]]$Roots
    )

    $matches = foreach ($root in $Roots) {
        Get-ChildItem -Path $root -Recurse -File -Filter "main.js" -ErrorAction SilentlyContinue |
            Where-Object { $_.FullName -match "integration[\\/]+oxygen[\\/]+main\.js$" } |
            Select-Object -ExpandProperty FullName
    }

    if ($matches) {
        Add-Check -Name "integration-asset" -Status "ok" -Detail ($matches -join ", ")
    } else {
        Add-Check -Name "integration-asset" -Status "missing" -Detail "No local integration\\oxygen\\main.js found under: $($Roots -join ', ')"
    }
}

function Add-BootstrapErrorChecks {
    param(
        [string]$OxygenRootPath
    )

    $jsDistPath = Join-Path $OxygenRootPath "builder\dist\js"
    $mapFiles = @(
        Get-ChildItem -Path $jsDistPath -Filter "app*.js.map" -File -ErrorAction SilentlyContinue
        Get-ChildItem -Path $jsDistPath -Filter "chunk-common*.js.map" -File -ErrorAction SilentlyContinue
    ) | Select-Object -Unique

    if (-not $mapFiles) {
        Add-Check -Name "bootstrap-error-anchor" -Status "missing" -Detail "No builder dist source maps found under $(Join-Path $OxygenRootPath 'builder\dist\js')"
        return
    }

    $patterns = @(
        "Entry assets/integration/oxygen/main.js not found",
        "vp-wp"
    )

    $rgPath = Get-Command "rg" -ErrorAction SilentlyContinue
    $matches = @()

    foreach ($file in $mapFiles) {
        foreach ($pattern in $patterns) {
            $found = $false

            if ($rgPath) {
                & $rgPath.Source --fixed-strings --quiet -- $pattern $file.FullName 2>$null
                $found = ($LASTEXITCODE -eq 0)
            } else {
                $found = Select-String -Path $file.FullName -Pattern $pattern -SimpleMatch -Quiet
            }

            if ($found) {
                $matches += [pscustomobject]@{
                    file = $file.FullName
                    pattern = $pattern
                }
            }
        }
    }

    if ($matches) {
        $detail = $matches |
            ForEach-Object { "{0} => {1}" -f $_.file, $_.pattern } |
            Select-Object -Unique
        Add-Check -Name "bootstrap-error-anchor" -Status "ok" -Detail ($detail -join "; ")
    } else {
        Add-Check -Name "bootstrap-error-anchor" -Status "missing" -Detail "No local builder source map contains the known vp-wp integration error anchor"
    }

    $integrationAssetCheck = $script:checks | Where-Object { $_.name -eq "integration-asset" } | Select-Object -Last 1
    $bootstrapAnchorCheck = $script:checks | Where-Object { $_.name -eq "bootstrap-error-anchor" } | Select-Object -Last 1

    if ($integrationAssetCheck.status -eq "missing" -and $bootstrapAnchorCheck.status -eq "ok") {
        Add-Check -Name "integration-bootstrap" -Status "suspect" -Detail "Builder source maps contain the vp-wp missing-entry anchor while no local integration\\oxygen\\main.js asset exists"
    }
}

function Add-DockerSyncMetadataChecks {
    param(
        [string]$ScriptPath
    )

    if (-not (Test-Path $ScriptPath)) {
        Add-Check -Name "docker-sync-script" -Status "missing" -Detail $ScriptPath
        return
    }

    Add-Check -Name "docker-sync-script" -Status "ok" -Detail $ScriptPath

    $content = Get-Content -Path $ScriptPath -Raw
    $containerDefault = [regex]::Match($content, 'OXY_HTML_CONVERTER_DOCKER_CONTAINER\s*\|\|\s*"([^"]+)"').Groups[1].Value
    $pluginPathDefault = [regex]::Match($content, 'OXY_HTML_CONVERTER_DOCKER_PLUGIN_PATH\s*\|\|\s*"([^"]+)"').Groups[1].Value
    $pluginOwnerDefault = [regex]::Match($content, 'OXY_HTML_CONVERTER_DOCKER_PLUGIN_OWNER\s*\|\|\s*"([^"]+)"').Groups[1].Value

    $details = @()

    if ($containerDefault) {
        $details += "container=$containerDefault"
    }

    if ($pluginPathDefault) {
        $details += "pluginPath=$pluginPathDefault"
    }

    if ($pluginOwnerDefault) {
        $details += "pluginOwner=$pluginOwnerDefault"
    }

    if ($details) {
        Add-Check -Name "docker-sync-defaults" -Status "ok" -Detail ($details -join "; ")
    } else {
        Add-Check -Name "docker-sync-defaults" -Status "missing" -Detail "Could not parse sync defaults from $ScriptPath"
    }
}

$paths = @(
    "D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\plugins\core",
    "D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\plugins\core\skill\oxygen-builder-browser",
    "D:\WordPress\Html to Oxygen\oxygen",
    "C:\Users\Skicu\.codex\plugins\cache\openai-bundled\browser-use\0.1.0-alpha1\scripts\browser-client.mjs"
)

foreach ($path in $paths) {
    if (Test-Path $path) {
        Add-Check -Name "path" -Status "ok" -Detail $path
    } else {
        Add-Check -Name "path" -Status "missing" -Detail $path
    }
}

Add-BuilderDistChecks -OxygenRootPath $oxygenRoot
Add-IntegrationAssetChecks -Roots @($oxygenRoot, $workspaceRoot)
Add-BootstrapErrorChecks -OxygenRootPath $oxygenRoot
Add-DockerSyncMetadataChecks -ScriptPath $dockerSyncScriptPath

if (-not $SkipHttp) {
    $resolvedBaseUrl = [uri]$BaseUrl
    $urls = @(
        $resolvedBaseUrl.AbsoluteUri,
        ([uri]::new($resolvedBaseUrl, "wp-login.php")).AbsoluteUri,
        ([uri]::new($resolvedBaseUrl, "wp-admin/")).AbsoluteUri
    )

    if ($PostId) {
        $urls += (Build-DocumentBuilderUrl -Base $resolvedBaseUrl -Id $PostId)
    }

    if ($OpenUrl -or $ReturnUrl) {
        $urls += (Build-BrowseModeUrl -Base $resolvedBaseUrl -Open $OpenUrl -Return $ReturnUrl)
    }

    foreach ($url in $urls) {
        try {
            $response = & $curlPath -sS -I --max-time 5 $url 2>&1
            if ($LASTEXITCODE -eq 0) {
                $firstHttp = ($response | Select-String -Pattern "^HTTP/" | Select-Object -First 1).Line
                Add-Check -Name "http" -Status "ok" -Detail "$url $firstHttp"
            } else {
                $tail = ($response | Select-Object -Last 1)
                $status = if ($tail -match "timed out") { "timeout" } else { "error" }
                Add-Check -Name "http" -Status $status -Detail "$url $tail"
            }
        } catch {
            Add-Check -Name "http" -Status "error" -Detail "$url $($_.Exception.Message)"
        }
    }
}

if (-not $SkipDocker) {
    try {
        $docker = docker ps --format "{{.Names}}" 2>&1
        if ($LASTEXITCODE -eq 0) {
            $names = if ($docker) { ($docker -join ", ") } else { "<none>" }
            Add-Check -Name "docker" -Status "ok" -Detail $names
        } else {
            $tail = ($docker | Select-Object -Last 1)
            Add-Check -Name "docker" -Status "error" -Detail $tail
        }
    } catch {
        Add-Check -Name "docker" -Status "error" -Detail $_.Exception.Message
    }
}

$checks | ConvertTo-Json -Depth 3
