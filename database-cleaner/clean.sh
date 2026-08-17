#!/bin/zsh

# Check CSV files for duplicate tables with different titles
# CSV format: title|table|tag

check_file() {
    local file="$1"
    local errors=0
    
    if [[ ! -f "$file" ]]; then
        echo "File not found: $file"
        return 1
    fi
    
    # Skip files that don't have pipe-separated format (core_*.csv are single-column)
    if ! grep -q '|' "$file" 2>/dev/null; then
        echo "Skipping: $file (single-column format)"
        return 0
    fi
    
    echo "Checking: $file"
    
    # Use associative array to track table -> title mapping
    typeset -A table_titles
    table_titles=()
    
    while IFS='|' read -r title table tag || [[ -n "$title" ]]; do
        # Skip empty lines or lines without proper format
        [[ -z "$title" || -z "$table" ]] && continue
        
        # Trim whitespace
        title="${title## }"
        title="${title%% }"
        table="${table## }"
        table="${table%% }"
        
        if [[ -n "${table_titles[$table]}" ]]; then
            if [[ "${table_titles[$table]}" != "$title" ]]; then
                echo "  ERROR: Table '$table' has multiple titles:"
                echo "    - '${table_titles[$table]}'"
                echo "    - '$title'"
                ((errors++))
            fi
        else
            table_titles[$table]="$title"
        fi
    done < "$file"
    
    if [[ $errors -eq 0 ]]; then
        echo "  OK - No duplicate tables with different titles"
    else
        echo "  Found $errors duplicate(s)"
    fi
    
    return $errors
}

# Main
total_errors=0

if [[ $# -gt 0 ]]; then
    # Check specific files passed as arguments
    for file in "$@"; do
        check_file "$file"
        ((total_errors += $?))
    done
else
    # Check all CSV files in support directories
    for file in classes/support/*.csv premium/support/*.csv; do
        [[ -f "$file" ]] && check_file "$file"
        ((total_errors += $?))
    done
fi

echo ""
if [[ $total_errors -eq 0 ]]; then
    echo "All files OK!"
    exit 0
else
    echo "Total errors found: $total_errors"
    exit 1
fi
