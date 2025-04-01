<?php

namespace flight\apm\presenter;

interface PresenterInterface
{
    /**
     * Get dashboard data for widgets
     *
     * @param string $threshold ISO 8601 formatted date string
     * @return array Dashboard widget data
     */
    public function getDashboardData(string $threshold): array;

    /**
     * Get paginated request log data with search capability
     *
     * @param string $threshold ISO 8601 formatted date string
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param string $search Optional search term
     * @return array Request data and pagination info
     */
    public function getRequestsData(string $threshold, int $page, int $perPage, string $search = ''): array;

    /**
     * Fetch detailed information for a specific request
     *
     * @param string $requestId The request ID
     * @return array Request details including middleware, queries, errors, cache operations, and custom events
     */
    public function getRequestDetails(string $requestId): array;

	/**
     * Get available event keys for search filters
     * 
     * @param string $threshold Timestamp threshold
     * @return array List of unique event keys
     */
    public function getEventKeys(string $threshold): array;
}
