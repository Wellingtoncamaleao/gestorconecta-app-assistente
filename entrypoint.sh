#!/bin/bash
# ASSISTENTE - Entrypoint
# Corrige permissoes dos volumes montados e inicia supervisord

# Volumes montados vem com dono root - corrigir para claude-user
chown -R claude-user:claude-user /home/claude-user/.claude 2>/dev/null
chown -R claude-user:claude-user /var/assistente 2>/dev/null
chmod -R 775 /var/assistente

# Garantir que diretorios existem
mkdir -p /var/assistente/fila/processados /var/assistente/dedup /var/assistente/logs /tmp/assistente /workspace
chown -R claude-user:claude-user /tmp/assistente /workspace

# Restaurar .claude.json do backup se nao existir
if [ ! -f /home/claude-user/.claude/.claude.json ]; then
    BACKUP=$(ls -t /home/claude-user/.claude/backups/.claude.json.backup.* 2>/dev/null | head -1)
    if [ -n "$BACKUP" ]; then
        cp "$BACKUP" /home/claude-user/.claude/.claude.json
        chown claude-user:claude-user /home/claude-user/.claude/.claude.json
        echo "[entrypoint] Restaurado .claude.json do backup"
    fi
fi

echo "[entrypoint] Permissoes corrigidas, iniciando supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
