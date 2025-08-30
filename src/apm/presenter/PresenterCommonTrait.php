<?php

declare(strict_types=1);

namespace flight\apm\presenter;

trait PresenterCommonTrait
{
    /**
     * Calculate percentile from an array of values
	 *
	 * @param array $data - The array of values
	 * @param int $percentile - The percentile to calculate
	 * @return float
     */
    protected function calculatePercentile(array $data, int $percentile): float
    {
        if (empty($data)) return 0;
        sort($data);
        $index = ($percentile / 100) * (count($data) - 1);
        $lower = floor($index);
        $upper = ceil($index);
        if ($lower == $upper) return $data[$lower];
        $fraction = $index - $lower;
        return $data[$lower] + $fraction * ($data[$upper] - $data[$lower]);
    }

    /**
     * Check if the database has JSON functions
	 *
	 * @return bool
     */
    protected function databaseSupportsJson(): bool
    {
        try {
            $this->db->query("SELECT json_extract('', '$')");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if IP addresses should be masked
	 *
	 * @return bool
     */
    protected function shouldMaskIpAddresses(): bool
    {
        return $this->config['apm']['mask_ip_addresses'] ?? false;
    }

    /**
     * Mask an IP address
	 *
	 * @param string $ip - The IP address to mask
	 * @return string - The masked IP address
     */
    protected function maskIpAddress(string $ip): string
    {
        $ipCharacter = strpos($ip, '.') !== false ? '.' : ':';
        $parts = explode($ipCharacter, $ip);
        if (count($parts) === 4) {
            $parts[3] = str_repeat('x', strlen($parts[3]));
        }
        if (count($parts) > 4) {
            $parts[count($parts) - 1] = str_repeat('x', strlen($parts[count($parts) - 1]));
        }
        return implode('.', $parts);
    }

    /**
     * Get operator options for event value filtering
	 *
	 * @return array<int, array<string, string>>
     */
    public function getEventValueOperators(): array
    {
        return [
            ['id' => 'contains', 'name' => 'Contains', 'desc' => 'Value contains the text (case-insensitive)'],
            ['id' => 'exact', 'name' => 'Equals', 'desc' => 'Value exactly matches the text'],
            ['id' => 'starts_with', 'name' => 'Starts with', 'desc' => 'Value starts with the text'],
            ['id' => 'ends_with', 'name' => 'Ends with', 'desc' => 'Value ends with the text'],
            ['id' => 'greater_than', 'name' => '>', 'desc' => 'Value is greater than (numeric comparison)'],
            ['id' => 'less_than', 'name' => '<', 'desc' => 'Value is less than (numeric comparison)'],
            ['id' => 'greater_than_equal', 'name' => '>=', 'desc' => 'Value is greater than or equal to (numeric comparison)'],
            ['id' => 'less_than_equal', 'name' => '<=', 'desc' => 'Value is less than or equal to (numeric comparison)'],
        ];
    }
}
