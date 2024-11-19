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
    $verbose = ($argv[1] ?? '') === '-v' ?? false;
    $fullCommand = $command . ' 2>&1';
    $commandDecorated = "\033[1m$fullCommand\033[0m";
    if ($verbose) {
        echo "Running command: $commandDecorated\n";
    }
    exec($command . ' 2>&1', $output, $returnVar);
    return [$output, $returnVar];
}

// Function to check if PHP-FPM is installed and fetch its process name
function checkPhpFpm()
{
    $phpFpmInstalled = false;
    $processName = '';

    $versions = ['php-fpm', 'php5-fpm', 'php-fpm7.2'];
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

    $configFiles = [];
    foreach ($output as $line) {
        if (preg_match('/configuration file\s*(.+)/', $line, $matches)) {
            $configFiles[] = trim($matches[1]);
        }
        if (preg_match('/included configuration file.*:\s*(.+)/', $line, $matches)) {
            $configFiles[] = trim($matches[1]);
        }
    }

    return $configFiles;
}

// Function to extract a configuration value for a specific pool
function getConfigValue($pool, $configKey, $configFiles)
{
    foreach ($configFiles as $file) {
        if (strpos($file, $pool) !== false) {
            [$output] = runCommand("grep '$configKey' $file | awk -F'=' '{print $2}' | sed 's/ //g'");
            return intval($output[0] ?? 0);
        }
    }

    return 0;
}

// Function to calculate memory usage of processes
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

// Function to display PHP-FPM pool information
function displayPoolInformation($processName, $configFiles)
{
    printHeader('List of PHP-FPM pools');

    [$pools] = runCommand("ps aux | grep '$processName' | grep -v grep | grep pool | awk '{print $13}' | sort | uniq");
    if (empty($pools)) {
        echo "\033[33mNo active PHP-FPM pools detected.\033[0m\n";
        return;
    }

    foreach ($pools as $pool) {
        echo "\033[36mPool: $pool\033[0m\n";

        [$pids] = runCommand("ps aux | grep '$processName' | grep -v grep | grep '$pool' | awk '{print $2}'");

        $largestProcessMemory = getLargestProcessMemory($pids);
        $maxChildren = getConfigValue($pool, 'pm.max_children', $configFiles);
        $totalPotentialMemory = $largestProcessMemory * $maxChildren;

        echo "\tProcesses: " . count($pids) . "\n";
        echo "\tLargest process in this pool (KB): \033[1m$largestProcessMemory KB\033[0m\n";
        echo "\tTotal potential memory usage (KB): \033[1m$totalPotentialMemory KB\033[0m\n";
    }
}

// Function to display server memory usage
function displayServerMemoryInformation()
{
    printHeader('Server memory usage statistics');

    $totalMemory = 0;
    if (file_exists('/proc/meminfo')) {
        $lines = file('/proc/meminfo');
        foreach ($lines as $line) {
            if (strpos($line, 'MemTotal') === 0) {
                $parts = preg_split('/\s+/', $line);
                $totalMemory = intval($parts[1]) / 1024; // Convert to MB
                break;
            }
        }
    }

    echo "Total server memory: \033[1m$totalMemory MB\033[0m\n";

    [$availableMemory] = runCommand("free -m | awk '/Mem/ {print $7}'");
    $availableMemory = intval($availableMemory[0] ?? 0);

    echo "Available memory for PHP-FPM: \033[1m$availableMemory MB\033[0m\n";
}

// Function to display pool-specific recommendations
function displayRecommendationsPerPool($processName, $configFiles)
{
    printHeader('Recommendations per pool');

    [$pools] = runCommand("ps aux | grep '$processName' | grep -v grep | grep pool | awk '{print $13}' | sort | uniq");
    if (empty($pools)) {
        echo "\033[33mNo recommendations – no active PHP-FPM pools detected.\033[0m\n";
        return;
    }

    foreach ($pools as $pool) {
        echo "\033[36mPool: $pool\033[0m\n";

        [$pids] = runCommand("ps aux | grep '$processName' | grep -v grep | grep '$pool' | awk '{print $2}'");
        $largestProcessMemory = getLargestProcessMemory($pids);

        [$availableMemory] = runCommand("free -m | awk '/Mem/ {print $7}'");
        $availableMemory = intval($availableMemory[0] ?? 0);

        $recommendedMaxChildren = intval(($availableMemory * 1024) / $largestProcessMemory);
        $maxChildren = getConfigValue($pool, 'pm.max_children', $configFiles);

        echo "\tRecommended max_children: \033[1m$recommendedMaxChildren\033[0m\n";
        echo "\tCurrent max_children: \033[1m$maxChildren\033[0m\n";
    }
}

// Main function
function main()
{
    $processName = checkPhpFpm();
    $configFiles = getFpmConfigFiles($processName);

    printHeader('Loaded PHP-FPM Configuration Files');
    foreach ($configFiles as $file) {
        echo "\t$file\n";
    }

    displayPoolInformation($processName, $configFiles);
    displayServerMemoryInformation();
    displayRecommendationsPerPool($processName, $configFiles);
}

// Entry point
main();
