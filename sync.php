<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/partials.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/thumbnails.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/utils.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/registry.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['data'], $data['iv'], $data['salt'], $data['keyid'])) {
        send_json_response(null, 'Malformed request.', 400);
    }

    $id = trim($data['keyid']);
    unset($data['keyid']);

    if (strlen($id) !== 64) {
        send_json_response(null, 'Malformed storage ID', 400);
    }

    try {
        $exists = METASTORAGE->has_storage_data($id);

        if (!METASTORAGE->put_storage_data($id, $data)) {
            send_json_response(null, 'failed to save storage data', 500);
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
        send_json_response(null, 'Failed to save data', 500);
    }

    send_json_response(['id' => $id, 'rewritten' => $exists], null, 200);
}

if (CLIENT_REQUIRES_JSON && isset($_GET['id'])) {
    $id = trim($_GET['id']);
    try {
        $data = METASTORAGE->get_storage_data($id);
        if ($data === null) {
            send_json_response(null, 'Not found', 404);
        }
        send_json_response($data, null, 200);
    } catch (Exception $e) {
        error_log($e->getMessage());
        send_json_response(null, 'Failed to get storage data', 500);
    }
}

?>
<!DOCTYPE html>
<html>

<head>
    <?php html_head("data synchronization"); ?>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2>data synchronization</h2>
        <p>sync your file upload history and favorites across devices</p>
        <p><b>make sure your code is long enough!</b></p>
        <p class="error shake"><b><u>anyone who obtains your code can modify your data!!!</u></b></p>
    </header>
    <main>
        <noscript>JavaScript is required for data synchronization.</noscript>

        <form id="sync-data">
            <div>
                <input type="text" autocapitalize="off" autocorrect="off" autocomplete="off" id="code"
                    placeholder="enter the code...">
                <div class="buttons">
                    <button type="submit">save</button>
                    <button type="reset">load</button>
                </div>
            </div>
            <p id="status"></p>
        </form>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php html_motd(); ?>
    </footer>
</body>

<script src="/static/scripts/sync.js"></script>
<script src="/static/scripts/pages/sync.js"></script>

</html>