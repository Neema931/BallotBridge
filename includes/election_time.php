<?php

function is_valid_election_timezone(string $timezone): bool
{
    static $identifiers = null;
    if ($identifiers === null) {
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        $identifiers[] = 'UTC';
    }

    return in_array($timezone, $identifiers, true);
}

function parse_election_local_time(string $value, string $timezone): ?DateTimeImmutable
{
    if (!is_valid_election_timezone($timezone)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $value,
        new DateTimeZone($timezone)
    );
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i') !== $value
    ) {
        return null;
    }

    return $date;
}

function parse_stored_election_time(string $value, string $timezone): ?DateTimeImmutable
{
    if (!is_valid_election_timezone($timezone)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        new DateTimeZone($timezone)
    );
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value
    ) {
        return null;
    }

    return $date;
}

function election_is_within_schedule(array $election, ?DateTimeImmutable $now = null): bool
{
    $timezone = isset($election['timezone']) && is_string($election['timezone'])
        ? $election['timezone']
        : '';
    $start = isset($election['start_datetime']) && is_string($election['start_datetime'])
        ? parse_stored_election_time($election['start_datetime'], $timezone)
        : null;
    $end = isset($election['end_datetime']) && is_string($election['end_datetime'])
        ? parse_stored_election_time($election['end_datetime'], $timezone)
        : null;

    if ($start === null || $end === null) {
        return false;
    }

    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone($timezone));
    return $now >= $start && $now <= $end;
}

function election_is_upcoming(array $election, ?DateTimeImmutable $now = null): bool
{
    $timezone = isset($election['timezone']) && is_string($election['timezone'])
        ? $election['timezone']
        : '';
    $start = isset($election['start_datetime']) && is_string($election['start_datetime'])
        ? parse_stored_election_time($election['start_datetime'], $timezone)
        : null;
    $end = isset($election['end_datetime']) && is_string($election['end_datetime'])
        ? parse_stored_election_time($election['end_datetime'], $timezone)
        : null;

    if ($start === null || $end === null) {
        return false;
    }

    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone($timezone));
    return $start > $now && $end > $now;
}

function election_has_not_closed(array $election, ?DateTimeImmutable $now = null): bool
{
    $timezone = isset($election['timezone']) && is_string($election['timezone'])
        ? $election['timezone']
        : '';
    $end = isset($election['end_datetime']) && is_string($election['end_datetime'])
        ? parse_stored_election_time($election['end_datetime'], $timezone)
        : null;

    if ($end === null) {
        return false;
    }

    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone($timezone));
    return $end >= $now;
}

function format_election_local_time(string $value, string $timezone): string
{
    $date = parse_stored_election_time($value, $timezone);
    if ($date === null) {
        throw new RuntimeException('An election has an invalid stored date or timezone.');
    }

    return $date->format('Y-m-d H:i T');
}
