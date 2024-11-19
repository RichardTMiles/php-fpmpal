<?php


echo "\n";
echo "\033[38;5;81m (        )  (         (     (       *                      \n";
echo "\033[38;5;51m\033[1m )\ )  ( /(  )\ )      )\ )  )\ )  (  `           \033[33m\033[1m      (   \n";
echo "\033[38;5;45m(()/(  )\())(()/(     (()/( (()/(  )\))(          \033[38;5;214m   )  )\  \n";
echo "\033[38;5;33m /(_))((_)\  /(_))     /(_)) /(_))((_)()\033[38;5;208m `  )   ( /( ((_) \n";
echo "\033[38;5;27m(_))   _((_)(_))      (_))_|(_))  (_()((_) \033[38;5;124m/(/(   )(_)) _   \n";
echo "\033[0m\033[38;5;21m| _ \\ | || || _ \\ ___ | |_  | _ \\ |  \\/  |\033[38;5;88m\033[1m((_)_\\ \033[38;5;88m((_)\033[0m\033[38;5;9m_ | |  \n";
echo "\033[38;5;21m|  _/ | __ ||  _/|___|| __| |  _/ | |\\/| |\033[38;5;9m| '_ \\";
echo "\033[38;5;88m\033[1m)\033[0m\033[38;5;9m/ _` || |  \n";
echo "\033[38;5;21m|_|   |_||_||_|       |_|   |_|   |_|  |_|\033[38;5;9m| .__/ \\__,_||_|  \n";

for ($i = -9; $i <= 21; ++$i) {
    echo "\033[38;5;{$i}m=\033[0m";
}
echo "\033[38;5;9m |_| ";
for ($i = 21; $i >= 15; --$i) {
    echo "\033[38;5;{$i}m=\033[0m";
}
echo "\033[0m\n";

// Utility function to print headers with formatting
function printHeader($title)
{
    echo "\n";
    echo "\033[32m" . str_repeat('=', 5) . " $title " . str_repeat('=', 5) . "\033[0m\n";
}

// Function to execute shell commands and return the output
function runCommand($command)
{
    global $argv;
    $verbose = ($argv[1] ?? '') === '-v';
    $fullCommand = $command . ' 2>&1';
    if ($verbose) {
        echo "\033[1mRunning command:\033[0m $fullCommand\n";
    }
    exec($fullCommand, $output, $returnVar);
    return [$output, $returnVar];
}

// Function to check if PHP-FPM is installed and fetch its process name
function checkPhpFpm()
{
    $phpFpmInstalled = false;
    $processName = '';

    $versions = ['php-fpm', 'php5-fpm', 'php-fpm7.2', 'php-fpm8.0', 'php-fpm8.1'];
    foreach ($versions as $version) {
        [$output, $returnVar] = runCommand("$version -v");
        if ($returnVar === 0) {
            $phpFpmInstalled = true;
            $processName = $version;
            break;
        }
    }

    if (!$phpFpmInstalled) {
        echo "\033[31m!!! PHP-FPM not detected. Exiting. !!!\033[0m\n";
        exit(1);
    }

    return $processName;
}

// Function to retrieve loaded PHP-FPM configuration files
function getFpmConfigFiles($processName)
{
    [$output, $returnVar] = runCommand("$processName -tt");
    if ($returnVar !== 0) {
        echo "\033[31mFailed to retrieve PHP-FPM configuration files. Ensure PHP-FPM is accessible.\033[0m\n";
        exit(1);
    }

    $mainConfigFile = '';
    foreach ($output as $line) {
        if (preg_match('/configuration file\s*(.+)/', $line, $matches)) {
            $mainConfigFile = trim($matches[1]);
        }
    }

    if (empty($mainConfigFile)) {
        echo "\033[31mFailed to retrieve PHP-FPM configuration files. Ensure PHP-FPM is accessible.\033[0m\n";
        exit(1);
    }

    $configFiles = [];

    [$output] = runCommand("grep -E 'include=|prefix' $mainConfigFile");

    $prefix = '/';
    foreach ($output as $line) {
        if (preg_match('/prefix\s*\(([^)]+)\)/', $line, $matches)) {
            $prefix = rtrim(trim($matches[1]), '/') . '/';
            continue;
        }
    }
    foreach ($output as $line) {
        if (preg_match('/include\s*=\s*(.*)/', $line, $matches)) {
            $includePath = $prefix . rtrim(trim($matches[1]), '/');
            foreach (glob($includePath) as $includedFile) {
                $configFiles[] = $includedFile;
            }
        }
    }

    if (empty($configFiles)) {
        echo "\033[31mNo PHP-FPM configuration files found. Exiting.\033[0m\n";
        exit(1);
    }

    return $configFiles;
}

// Function to retrieve specific configuration values for a pool
function getConfigValue($pool, $configKey, $configFiles)
{
    foreach ($configFiles as $file) {
        if (str_contains($file, $pool)) {
            [$output] = runCommand("grep '$configKey' $file | awk -F'=' '{print $2}' | sed 's/ //g'");
            return intval($output[0] ?? 0);
        }
    }

    return 0;
}

// Function to display PHP-FPM version and status
function displayPhpFpmInfo($processName)
{
    printHeader('PHP-FPM General Information');

    // Get PHP-FPM version
    [$versionOutput] = runCommand("$processName -v");
    echo "\033[36mVersion:\033[0m " . ($versionOutput[0] ?? 'Unknown') . "\n";

    // Check if PHP-FPM service is running
    [$statusOutput] = runCommand("ps aux | grep '$processName' | grep -v grep");
    $isRunning = count($statusOutput) > 0;
    echo "\033[36mStatus:\033[0m " . ($isRunning ? "\033[32mRunning\033[0m" : "\033[31mNot Running\033[0m") . "\n";
}

// Function to display detailed pool configurations
function getDetailedPoolConfig($pool, $configFiles)
{
    $keys = ['pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers', 'pm.max_requests'];
    $config = [];
    
    foreach ($keys as $key) {
        $config[$key] = getConfigValue($pool, $key, $configFiles);
    }

    return $config;
}

function displayDetailedPoolConfigs($processName, $configFiles)
{
    printHeader('PHP-FPM Pool Configuration Details');

    [$pools] = runCommand("ps aux | grep '$processName' | grep -v grep | grep pool | awk '{print $13}' | sort | uniq");
    if (empty($pools)) {
        echo "\033[33mNo active PHP-FPM pools detected.\033[0m\n";
        return;
    }

    foreach ($pools as $pool) {
        echo "\033[36mPool: $pool\033[0m\n";

        $config = getDetailedPoolConfig($pool, $configFiles);

        foreach ($configFiles as $file) {
            if (str_contains($file, $pool)) {
                echo "\t\033[1m$file\033[0m\n";
            }
        }

        foreach ($config as $key => $value) {
            echo "\t$key: \033[1m$value\033[0m\n";
        }
    }
}

// Function to calculate memory usage
function getLargestProcessMemory($pids)
{
    $largestMemory = 0;

    foreach ($pids as $pid) {
        [$output] = runCommand("pmap -d $pid | grep 'writeable/private' | awk '{print $4}' | sed 's/K//'");
        $memory = intval($output[0] ?? 0);
        if ($memory > $largestMemory) {
            $largestMemory = $memory;
        }
    }

    return $largestMemory; // Memory in KB
}

function getAverageProcessMemory($pids)
{
    $totalMemory = 0;
    $processCount = count($pids);

    foreach ($pids as $pid) {
        [$output] = runCommand("pmap -d $pid | grep 'writeable/private' | awk '{print $4}' | sed 's/K//'");
        $memory = intval($output[0] ?? 0);
        $totalMemory += $memory;
    }

    return $processCount > 0 ? $totalMemory / $processCount : 0; // Memory in KB
}

function displayMemoryBreakdown($processName, $configFiles)
{
    printHeader('Memory Breakdown Per Pool');

    [$pools] = runCommand("ps aux | grep '$processName' | grep -v grep | grep pool | awk '{print $13}' | sort | uniq");
    if (empty($pools)) {
        echo "\033[33mNo active PHP-FPM pools detected.\033[0m\n";
        return;
    }

    foreach ($pools as $pool) {
        echo "\033[36mPool: $pool\033[0m\n";

        [$pids] = runCommand("ps aux | grep '$processName' | grep -v grep | grep '$pool' | awk '{print $2}'");

        $largestMemory = getLargestProcessMemory($pids);
        $averageMemory = getAverageProcessMemory($pids);
        $totalMemory = $largestMemory * count($pids);

        echo "\tLargest Process Memory (KB): \033[1m$largestMemory\033[0m\n";
        echo "\tAverage Process Memory (KB): \033[1m$averageMemory\033[0m\n";
        echo "\tTotal Memory Used by Pool (KB): \033[1m$totalMemory\033[0m\n";
    }
}

// Function to display active connections
function displayActiveConnections($processName)
{
    printHeader('Active Connections Per Pool');

    [$pools] = runCommand("ps aux | grep '$processName' | grep -v grep | grep pool | awk '{print $13}' | sort | uniq");
    if (empty($pools)) {
        echo "\033[33mNo active PHP-FPM pools detected.\033[0m\n";
        return;
    }

    foreach ($pools as $pool) {
        echo "\033[36mPool: $pool\033[0m\n";

        [$connections] = runCommand("ps aux | grep '$processName' | grep -v grep | grep '$pool' | wc -l");
        echo "\tActive Connections: \033[1m" . ($connections[0] ?? '0') . "\033[0m\n";
    }
}

// Function to display error logs
function displayErrorLogs($processName)
{
    printHeader('PHP-FPM Error Logs');

    [$logs] = runCommand("journalctl -u $processName | tail -n 10");
    echo implode("\n", $logs) . "\n";
}

// Function to display recommendations
function displayRecommendations($processName, $configFiles)
{
    printHeader('Performance Tuning Recommendations');

    [$pools] = runCommand("ps aux | grep '$processName' | grep -v grep | grep pool | awk '{print $13}' | sort | uniq");
    if (empty($pools)) {
        echo "\033[33mNo recommendations – no active PHP-FPM pools detected.\033[0m\n";
        return;
    }

    foreach ($pools as $pool) {
        echo "\033[36mPool: $pool\033[0m\n";

        [$pids] = runCommand("ps aux | grep '$processName' | grep -v grep | grep '$pool' | awk '{print $2}'");
        $largestMemory = getLargestProcessMemory($pids);

        [$availableMemory] = runCommand("free -m | awk '/Mem/ {print $7}'");
        $recommendedMaxChildren = intval(($availableMemory[0] * 1024) / $largestMemory);

        echo "\tRecommended max_children: \033[1m$recommendedMaxChildren\033[0m\n";
    }
}

// Main function
function main()
{
    $processName = checkPhpFpm();
    $configFiles = getFpmConfigFiles($processName);

    displayPhpFpmInfo($processName);
    displayDetailedPoolConfigs($processName, $configFiles);
    displayMemoryBreakdown($processName, $configFiles);
    displayActiveConnections($processName);
    displayErrorLogs($processName);
    displayRecommendations($processName, $configFiles);
}

// Entry point
main();
