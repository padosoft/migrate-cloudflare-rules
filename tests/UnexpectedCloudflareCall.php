<?php

namespace Padosoft\MigrateCloudflareRules\Tests;

/**
 * Thrown by the fake Cloudflare API when the command performs a request that the test did not declare.
 * It extends \Error on purpose: the command catches \Exception in many places and would otherwise
 * swallow the failure, turning a broken test into a green one.
 */
final class UnexpectedCloudflareCall extends \Error {}
