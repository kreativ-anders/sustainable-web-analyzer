<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * A gate that stops an analysis early. A page that reaches one is not measured completely (the real
 * weight is higher) and is so heavy or slow that it gets the worst rating, see Co2::report().
 */
enum Limit: string
{
    /** max_execution_time: the analysis ran out of time. */
    case Time = 'time';

    /** max_resources: the page references more sub-resources than are measured. */
    case Resources = 'resources';

    /** max_redirects: a sub-resource redirects too often. */
    case Redirects = 'redirects';

    /** max_resource_bytes: a single transfer (page or sub-resource, downloaded or by Content-Length) is too large. */
    case ResourceBytes = 'resource_bytes';

    /** max_download_bytes: the full downloads of sub-resources (fallback for missing Content-Length) are too large in total. */
    case DownloadBytes = 'download_bytes';
}
