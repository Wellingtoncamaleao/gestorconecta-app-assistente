<?php
/**
 * ASSISTENTE IA PESSOAL - Configuracao
 * Le de env vars (container) com fallback para valores locais
 */

// Supabase
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://qmzrfwqopjaxruiegzsn.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');

// Telegram Bot
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_CHAT_IDS', json_decode(getenv('TELEGRAM_CHAT_IDS') ?: '[]', true) ?: []);

// Evolution API (WhatsApp)
define('EVOLUTION_API_URL', getenv('EVOLUTION_API_URL') ?: 'https://evolution.gestorconecta.com.br');
define('EVOLUTION_API_KEY', getenv('EVOLUTION_API_KEY') ?: '');
define('EVOLUTION_INSTANCIA', getenv('EVOLUTION_INSTANCIA') ?: 'assistente');
define('WHATSAPP_CHAT_IDS', json_decode(getenv('WHATSAPP_CHAT_IDS') ?: '[]', true) ?: []);

// Fila
define('FILA_DIR', '/var/assistente/fila');
define('LOGS_DIR', '/var/assistente/logs');