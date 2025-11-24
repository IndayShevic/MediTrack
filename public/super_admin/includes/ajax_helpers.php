<?php
if (!function_exists('setup_dashboard_ajax_capture')) {
    /**
     * Detects if the request is an AJAX dashboard content load and starts output buffering.
     */
    function setup_dashboard_ajax_capture(): bool
    {
        $isAjax = isset($_GET['ajax']) ||
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

        if ($isAjax && ob_get_level() === 0) {
            ob_start();
        }

        return $isAjax;
    }
}

if (!function_exists('deliver_dashboard_ajax_content')) {
    /**
     * Emits only the dashboard content section plus inline scripts for AJAX requests.
     */
    function deliver_dashboard_ajax_content(bool $isAjax): void
    {
        if (!$isAjax) {
            return;
        }

        $buffer = ob_get_clean();
        if ($buffer === false || $buffer === '') {
            exit;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($buffer);
        libxml_clear_errors();

        if (!$loaded) {
            echo $buffer;
            exit;
        }

        $xpath = new DOMXPath($dom);
        $contentHtml = '';

        $contentNodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' content-body ')]");
        if ($contentNodes instanceof DOMNodeList && $contentNodes->length > 0) {
            foreach ($contentNodes as $node) {
                $contentHtml .= $dom->saveHTML($node);
            }
        } else {
            $mainNodes = $dom->getElementsByTagName('main');
            if ($mainNodes->length > 0) {
                foreach ($mainNodes as $main) {
                    $contentHtml .= $dom->saveHTML($main);
                }
            } else {
                $contentHtml = $buffer;
            }
        }

        $scriptHtml = '';
        foreach ($dom->getElementsByTagName('script') as $script) {
            if ($script->hasAttribute('src')) {
                continue;
            }
            $scriptHtml .= $dom->saveHTML($script);
        }

        echo $contentHtml . $scriptHtml;
        exit;
    }
}

if (!function_exists('redirect_to_dashboard_shell')) {
    /**
     * For non-AJAX requests to inner dashboard pages, redirect into the unified
     * dashboard shell so that navigation and layout stay consistent.
     */
    function redirect_to_dashboard_shell(bool $isAjax): void
    {
        if ($isAjax) {
            return;
        }

        $self = basename($_SERVER['PHP_SELF'] ?? '');
        if ($self === 'dashboardnew.php') {
            return;
        }

        if (!function_exists('base_url')) {
            return;
        }

        $targetPath = 'super_admin/' . $self;
        $fullTarget = base_url($targetPath);
        $shellUrl = base_url('super_admin/dashboardnew.php') . '?target=' . rawurlencode($fullTarget);

        header('Location: ' . $shellUrl);
        exit;
    }
}

