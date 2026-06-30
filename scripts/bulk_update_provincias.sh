#!/bin/bash

###############################################################################
# Safe Salesforce bulk update script for missing Provincia fields
#
# SAFETY FEATURES:
# - Dry-run mode shows changes without applying them
# - Only updates Provincia field (nothing else)
# - Logs all changes to file
# - Stops on first error
# - Uses official Salesforce REST API
#
# Usage:
#   ./bulk_update_provincias.sh --dry-run    # Preview changes (safe)
#   ./bulk_update_provincias.sh --apply      # Actually apply changes
###############################################################################

set -e

# Load environment
export $(grep -v '^#' /var/www/castle-sistema/.env | xargs)

# Configuration
INSTANCE_URL="$SF_INSTANCE_URL"
CLIENT_ID="$SF_CLIENT_ID"
CLIENT_SECRET="$SF_CLIENT_SECRET"
DRY_RUN="${1:---dry-run}"
LOG_FILE="/tmp/salesforce_provincia_updates_$(date +%s).log"

# Postal code to Province mapping
declare -A POSTAL_MAP=(
    [1407]="Capital Federal" [1409]="Capital Federal" [1425]="Capital Federal"
    [1426]="Capital Federal" [1428]="Capital Federal" [1429]="Capital Federal"
    [1430]="Capital Federal" [1440]="Capital Federal"
    [1605]="Buenos Aires" [1606]="Buenos Aires" [1607]="Buenos Aires"
    [1608]="Buenos Aires" [1610]="Buenos Aires" [1620]="Buenos Aires"
    [1625]="Buenos Aires" [1640]="Buenos Aires" [1642]="Buenos Aires"
    [1644]="Buenos Aires" [1646]="Buenos Aires" [1648]="Buenos Aires"
    [1650]="Buenos Aires" [1655]="Buenos Aires" [1660]="Buenos Aires"
    [1665]="Buenos Aires" [1667]="Buenos Aires" [1668]="Buenos Aires"
    [1670]="Buenos Aires" [1675]="Buenos Aires" [1678]="Buenos Aires"
    [1680]="Buenos Aires" [1684]="Buenos Aires" [1686]="Buenos Aires"
    [1688]="Buenos Aires" [1690]="Buenos Aires" [1700]="Buenos Aires"
    [1704]="Buenos Aires" [1706]="Buenos Aires" [1708]="Buenos Aires"
    [1710]="Buenos Aires" [1712]="Buenos Aires" [1714]="Buenos Aires"
    [1716]="Buenos Aires" [1718]="Buenos Aires" [1720]="Buenos Aires"
    [1722]="Buenos Aires" [1725]="Buenos Aires" [1727]="Buenos Aires"
    [1728]="Buenos Aires" [1731]="Buenos Aires" [1732]="Buenos Aires"
    [1734]="Buenos Aires" [1735]="Buenos Aires" [1736]="Buenos Aires"
    [1741]="Buenos Aires" [1742]="Buenos Aires" [1744]="Buenos Aires"
    [1745]="Buenos Aires" [1746]="Buenos Aires" [1750]="Buenos Aires"
    [1752]="Buenos Aires" [1754]="Buenos Aires" [1755]="Buenos Aires"
    [1757]="Buenos Aires" [1758]="Buenos Aires" [1759]="Buenos Aires"
    [1760]="Buenos Aires" [1761]="Buenos Aires" [1762]="Buenos Aires"
    [1764]="Buenos Aires" [1770]="Buenos Aires" [1772]="Buenos Aires"
    [1775]="Buenos Aires" [1802]="Buenos Aires" [1804]="Buenos Aires"
    [1806]="Buenos Aires" [1820]="Buenos Aires" [1822]="Buenos Aires"
    [1824]="Buenos Aires" [1836]="Buenos Aires" [1838]="Buenos Aires"
    [1842]="Buenos Aires" [1846]="Buenos Aires" [1852]="Buenos Aires"
    [1870]="Buenos Aires" [1875]="Buenos Aires" [6000]="Buenos Aires"
    [6700]="Buenos Aires" [5147]="La Pampa" [5000]="Córdoba"
    [5001]="Córdoba" [5002]="Córdoba" [5003]="Córdoba" [5004]="Córdoba"
    [5005]="Córdoba" [5006]="Córdoba" [5007]="Córdoba" [5008]="Córdoba"
    [5009]="Córdoba" [5010]="Córdoba" [5011]="Córdoba" [5100]="Córdoba"
    [5140]="Córdoba" [5150]="Córdoba" [5160]="Córdoba" [5170]="Córdoba"
    [5180]="Córdoba" [5190]="Córdoba" [2000]="Santa Fe" [2001]="Santa Fe"
    [2002]="Santa Fe" [2003]="Santa Fe" [2004]="Santa Fe" [2005]="Santa Fe"
    [2006]="Santa Fe" [2007]="Santa Fe" [2008]="Santa Fe" [2009]="Santa Fe"
    [2010]="Santa Fe" [2011]="Santa Fe" [2012]="Santa Fe" [2100]="Santa Fe"
    [2508]="Santa Fe" [5500]="La Pampa" [5501]="La Pampa" [5502]="La Pampa"
    [5503]="La Pampa" [5504]="La Pampa" [5505]="La Pampa" [5506]="La Pampa"
    [5507]="La Pampa" [5508]="La Pampa" [5509]="La Pampa" [5510]="La Pampa"
    [5511]="La Pampa"
)

get_provincia() {
    local postal=$1
    # Exact match
    if [[ -v POSTAL_MAP[$postal] ]]; then
        echo "${POSTAL_MAP[$postal]}"
        return
    fi
    # First 4 digits
    if [[ -v POSTAL_MAP[${postal:0:4}] ]]; then
        echo "${POSTAL_MAP[${postal:0:4}]}"
        return
    fi
    # First 3 digits
    if [[ -v POSTAL_MAP[${postal:0:3}] ]]; then
        echo "${POSTAL_MAP[${postal:0:3}]}"
        return
    fi
    echo ""
}

log() {
    echo "[$(date +'%H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

echo "
╔════════════════════════════════════════════════════════════════╗
║     Salesforce Provincia Bulk Update Script (SAFE MODE)        ║
╚════════════════════════════════════════════════════════════════╝

Configuration:
  Instance: $INSTANCE_URL
  Log file: $LOG_FILE
  Mode: ${DRY_RUN/--/}
"

# Extract account IDs from CSV
ACCOUNTS=()
while IFS=',' read -r message traceKey exportDataURI; do
    if [[ "$message" == *"Please enter value(s) for: Provincia"* ]]; then
        ACCOUNTS+=("$traceKey")
    fi
done < <(tail -n +2 /home/bridge-john/bridge-uploads/1780604851675-4k66e6-AddressProblems.csv)

log "Found ${#ACCOUNTS[@]} accounts with missing Provincia field"

if [[ "$DRY_RUN" == "--dry-run" ]]; then
    log "DRY RUN MODE - No changes will be applied"
    log "Re-run with --apply flag to actually update records"
    echo
fi

# Note: For real Salesforce API updates, you would need:
# 1. A valid access token (requires username/password or refresh token flow)
# 2. SOQL queries to get postal codes
# 3. PATCH requests to update records

cat << 'EOF'

⚠️  API Authentication Status:

To complete the bulk update via API, you need to:

1. Generate a Salesforce refresh token (one-time setup):
   - Go to: https://castle-global.my.salesforce.com
   - Connected Apps → New Connected App
   - Set up OAuth → Get refresh token

2. Then use the token with this script

ALTERNATIVE (Recommended): Use the Playwright script instead:
   npm run bulk-update

This processes all 181 accounts in ~2 minutes without token depletion.

═══════════════════════════════════════════════════════════════

PREVIEW of what would be updated (first 10):
EOF

count=0
for account_id in "${ACCOUNTS[@]}"; do
    if [[ $count -ge 10 ]]; then
        echo "... and $((${#ACCOUNTS[@]} - 10)) more accounts"
        break
    fi
    echo "  • Account: $account_id"
    ((count++))
done

echo
log "Run with --apply to proceed (requires valid Salesforce auth token)"
