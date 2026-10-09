<?php
declare(strict_types=1);

/** Version und Produktdaten. Wird von Updates überschrieben. */
const APP_VERSION = '1.5';
const APP_NAME = 'HandwerkRechnung';
const APP_TAGLINE = 'Das Rechnungsprogramm für Handwerksbetriebe';
const APP_RELEASED = '2026-10-09';

function app_version(): string { return APP_VERSION; }
