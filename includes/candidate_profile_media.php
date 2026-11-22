<?php

function candidate_profile_store_photo($upload): ?string
{
    if ($upload === null) {
        return null;
    }
    if (!is_array($upload)) {
        throw new InvalidArgumentException('Choose a valid JPG or PNG headshot no larger than 2 MB.');
    }

    $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The headshot upload failed. Please try again.');
    }

    $temporaryPath = $upload['tmp_name'] ?? '';
    if (
        !is_string($temporaryPath)
        || !is_uploaded_file($temporaryPath)
        || !isset($upload['size'])
        || !is_int($upload['size'])
        || $upload['size'] < 1
        || $upload['size'] > 2 * 1024 * 1024
    ) {
        throw new InvalidArgumentException('Choose a JPG or PNG headshot no larger than 2 MB.');
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('The PHP fileinfo extension is required for headshot uploads.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $actualSize = filesize($temporaryPath);
    if (
        !isset($extensions[$mimeType])
        || $actualSize === false
        || $actualSize < 1
        || $actualSize > 2 * 1024 * 1024
    ) {
        throw new InvalidArgumentException('Choose a valid JPG or PNG headshot no larger than 2 MB.');
    }

    $uploadDirectory = __DIR__ . '/../assets/img/candidates';
    if (
        !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0755, true)
        && !is_dir($uploadDirectory)
    ) {
        throw new RuntimeException('Unable to create the candidate photo directory.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('Unable to save the uploaded candidate photo.');
    }

    return 'assets/img/candidates/' . $fileName;
}

function candidate_profile_delete_photo(?string $photoPath): void
{
    if (
        !is_string($photoPath)
        || preg_match('~\Aassets/img/candidates/[a-f0-9]{32}\.(?:jpg|png)\z~', $photoPath) !== 1
    ) {
        return;
    }

    $photoFile = __DIR__ . '/../' . $photoPath;
    if (is_file($photoFile) && !unlink($photoFile)) {
        error_log('Unable to remove a candidate profile photo.');
    }
}
