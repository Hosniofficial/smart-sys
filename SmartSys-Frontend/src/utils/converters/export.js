/**
 * Utility functions for exporting data
 */

/**
 * Download data as CSV file
 * @param {Array} data - Array of objects to export
 * @param {string} filename - Name of the file to download
 * @param {Object} options - Additional options
 */
export function downloadCSV(data, filename = 'export.csv', options = {}) {
  if (!Array.isArray(data) || data.length === 0) {
    console.warn('No data to export');
    return;
  }

  const {
    headers = null,
    delimiter = ',',
    encoding = 'utf-8'
  } = options;

  let csvContent = '';

  // Add headers if provided
  if (headers) {
    csvContent += headers.join(delimiter) + '\n';
  } else {
    // Use keys from first object as headers
    const keys = Object.keys(data[0]);
    csvContent += keys.join(delimiter) + '\n';
  }

  // Add data rows
  data.forEach(row => {
    const values = headers 
      ? headers.map(header => {
          const value = row[header] || '';
          return escapeCsvValue(value);
        })
      : Object.values(row).map(value => escapeCsvValue(value));
    
    csvContent += values.join(delimiter) + '\n';
  });

  // Create and download file
  const blob = new Blob(['\ufeff' + csvContent], { type: `text/csv;charset=${encoding}` });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

/**
 * Escape CSV values to handle commas, quotes, and newlines
 * @param {any} value - Value to escape
 * @returns {string} Escaped value
 */
function escapeCsvValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  
  const stringValue = String(value);
  
  // If value contains comma, quote, or newline, wrap in quotes and escape quotes
  if (stringValue.includes(',') || stringValue.includes('"') || stringValue.includes('\n')) {
    return '"' + stringValue.replace(/"/g, '""') + '"';
  }
  
  return stringValue;
}

export default {
  downloadCSV,
};
