import assert from 'node:assert/strict';
import test from 'node:test';
import {
  formatDateTimeUsingNativeLocale,
  formatDateTimeWithSqlSpace,
  formatDateForLocale,
  formatDateUsingNativeLocale,
  formatLocalDateDayEnUS,
  formatReturnDateTime,
  formatTimeUsingNativeLocale
} from '../src/utils/formatters/date.js';
import {
  compactNumberFormatArEG,
  formatPriceEnUSFixedTwoDecimalsOrZero,
  formatPriceEnUSFixedTwoDecimals,
  formatNumberEnUS,
  formatPriceEnUSMinimumTwoDecimals
} from '../src/utils/formatters/numbers.js';

test('formatNumberEnUS preserves the repeated en-US number formatting behavior', () => {
  const values = [1234.5, '1234.5', 'not-a-number', null, undefined, 0, -1234.5];

  for (const value of values) {
    const expected = (Number(value) || 0).toLocaleString('en-US');
    assert.equal(formatNumberEnUS(value), expected);
  }
});

test('formatNumberEnUS preserves explicitly configured fraction digits', () => {
  const cases = [
    [1234.56789, { maximumFractionDigits: 4 }],
    [1234.5, { minimumFractionDigits: 2, maximumFractionDigits: 2 }],
    ['not-a-number', { maximumFractionDigits: 4 }]
  ];

  for (const [value, options] of cases) {
    assert.equal(
      formatNumberEnUS(value, options),
      (Number(value) || 0).toLocaleString('en-US', options)
    );
  }
});

test('formatPriceEnUSMinimumTwoDecimals preserves the repeated price formatting behavior', () => {
  const values = [1234.5, '1234.5', 'not-a-number', null, undefined, '', 0, -1234.5];

  for (const value of values) {
    const expected = !value
      ? '0'
      : Number(value).toLocaleString('en-US', { minimumFractionDigits: 2 });
    assert.equal(formatPriceEnUSMinimumTwoDecimals(value), expected);
  }
});

test('formatPriceEnUSFixedTwoDecimals preserves the repeated fixed-price behavior', () => {
  const values = [1234.5, '1234.5', 'not-a-number', null, undefined, '', 0, -1234.5];
  const formatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  for (const value of values) {
    assert.equal(
      formatPriceEnUSFixedTwoDecimals(value),
      formatter.format(Number(value || 0))
    );
  }
});

test('formatPriceEnUSFixedTwoDecimalsOrZero preserves zero fallbacks for numeric coercion', () => {
  const values = [1234.5, '1234.5', 'not-a-number', null, undefined, '', 0, -1234.5];
  const formatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  for (const value of values) {
    assert.equal(
      formatPriceEnUSFixedTwoDecimalsOrZero(value),
      formatter.format(Number(value) || 0)
    );
  }
});

test('compactNumberFormatArEG uses the existing compact Arabic-Egyptian options', () => {
  const expectedFormatter = new Intl.NumberFormat('ar-EG', {
    notation: 'compact',
    maximumFractionDigits: 1
  });

  for (const value of [0, 1234, 1234567.8, -1234567]) {
    assert.equal(compactNumberFormatArEG.format(value), expectedFormatter.format(value));
  }
});

test('native-locale date helpers preserve falsy and invalid-date behavior', () => {
  const values = [null, undefined, '', 0, '2026-09-23T10:21:41', 'not-a-date'];
  const dateOptions = { day: '2-digit', month: '2-digit', year: 'numeric' };
  const dateTimeOptions = {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true
  };
  const timeOptions = { hour: '2-digit', minute: '2-digit' };

  for (const value of values) {
    const expectedDate = value
      ? new Date(value).toLocaleDateString('en-US', dateOptions)
      : '';
    const expectedDateTime = value
      ? new Date(value).toLocaleString('en-US', dateTimeOptions)
      : '';
    const expectedTime = value
      ? new Date(value).toLocaleTimeString('en-US', timeOptions)
      : '';

    assert.equal(formatDateUsingNativeLocale(value, 'en-US', dateOptions), expectedDate);
    assert.equal(formatDateTimeUsingNativeLocale(value, 'en-US', dateTimeOptions), expectedDateTime);
    assert.equal(formatTimeUsingNativeLocale(value, 'en-US', timeOptions), expectedTime);
  }
});

test('en-GB statement date formatter returns blank for missing values and preserves invalid input', () => {
  for (const value of [null, undefined, '', 0]) {
    assert.equal(formatDateForLocale(value, 'en-GB'), '');
  }
  assert.equal(formatDateForLocale('not-a-date', 'en-GB'), 'not-a-date');

  const date = '2026-09-23';
  const expected = new Intl.DateTimeFormat('en-GB', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  }).format(new Date(date));
  assert.equal(formatDateForLocale(date, 'en-GB'), expected);
});

test('formatLocalDateDayEnUS preserves local-day parsing and dash fallbacks', () => {
  const values = [null, undefined, '', 0, '2026-09-23', '2026-09-23T23:15:00Z', 'not-a-date'];

  for (const value of values) {
    let expected = '-';
    if (value) {
      const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
      expected = Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString('en-US');
    }

    assert.equal(formatLocalDateDayEnUS(value), expected);
  }
});

test('formatDateTimeWithSqlSpace preserves SQL timestamp parsing and invalid-value fallback', () => {
  const values = [null, undefined, '', 0, '2026-09-23 10:21:41', 'not-a-date'];
  const options = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true
  };

  for (const value of values) {
    let expected = '';
    if (value) {
      const parsed = new Date(String(value).replace(' ', 'T'));
      expected = Number.isNaN(parsed.getTime())
        ? String(value)
        : parsed.toLocaleString('en-US', options);
    }

    assert.equal(formatDateTimeWithSqlSpace(value, options), expected);
  }
});

test('formatReturnDateTime preserves the existing date/time selection and fallbacks', () => {
  const values = [
    ['', ''],
    ['2026-09-23', ''],
    ['2026-09-23T10:21:41', ''],
    ['2026-09-23', '2026-09-23 11:22:00'],
    ['invalid', ''],
    ['', '2026-09-23 11:22:00']
  ];
  const dateOptions = { day: '2-digit', month: '2-digit', year: 'numeric' };
  const dateTimeOptions = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true
  };

  for (const [returnDate, createdAt] of values) {
    const dateValue = String(returnDate || createdAt || '');
    let expected = '';
    if (dateValue) {
      const datePart = dateValue.slice(0, 10);
      const directTime = dateValue.match(/[T ](\d{2}:\d{2}(?::\d{2})?)/)?.[1];
      const createdTime = String(createdAt || '').match(/[T ](\d{2}:\d{2}(?::\d{2})?)/)?.[1];
      const time = directTime || createdTime;
      if (!time) {
        expected = new Date(datePart).toLocaleDateString('en-US', dateOptions);
      } else {
        const normalizedTime = time.length === 5 ? `${time}:00` : time;
        const date = new Date(`${datePart}T${normalizedTime}`);
        expected = Number.isNaN(date.getTime())
          ? new Date(datePart).toLocaleDateString('en-US', dateOptions)
          : date.toLocaleString('en-US', dateTimeOptions);
      }
    }

    assert.equal(formatReturnDateTime(returnDate, createdAt), expected);
  }
});
