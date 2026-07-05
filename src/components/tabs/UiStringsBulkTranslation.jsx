import { useCallback, useEffect, useRef, useState } from 'react';
import LanguageSelect from '../ui/LanguageSelect';
import Notice from '../ui/Notice';
import { useTargetLanguages } from '../../hooks/useTargetLanguages';
import {
  fetchUiStringsStats,
  scanUiStrings,
  sandboxTestUiStringFile,
  uiStringsJobAction,
  uiStringsJobStep,
} from '../../api/uiStrings';

const config = window.polymartAiSettings ?? {};
const devMode = Boolean(config.devMode);

function formatScannedAt(timestamp) {
  if (!timestamp) {
    return 'هرگز';
  }

  return new Date(timestamp * 1000).toLocaleString('fa-IR');
}

function extractDebugInfo(payload) {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  return payload.debug_info ?? payload.data?.debug_info ?? null;
}

function extractApiErrorMessage(error) {
  const data = error?.response?.data;

  if (typeof data === 'string' && data.trim()) {
    if (data.trim().startsWith('{')) {
      try {
        const parsed = JSON.parse(data);
        if (typeof parsed?.message === 'string' && parsed.message) {
          return parsed.message;
        }
      } catch {
        return data.trim().slice(0, 300);
      }
    }

    return data.trim().slice(0, 300);
  }

  if (data && typeof data === 'object') {
    if (typeof data.message === 'string' && data.message) {
      return data.code ? `${data.message} (${data.code})` : data.message;
    }

    if (typeof data.error === 'string' && data.error) {
      return data.error;
    }
  }

  if (error?.response?.status) {
    return `HTTP ${error.response.status}: ${error?.message || 'خطای ناشناخته'}`;
  }

  return error?.message || 'خطای ناشناخته';
}

function normalizeSandboxResult(payload, inputPath) {
  if (typeof payload === 'string') {
    const rawResponse = payload.trim();

    if (rawResponse) {
      try {
        return normalizeSandboxResult(JSON.parse(rawResponse), inputPath);
      } catch {
        return {
          input_path: inputPath,
          resolved_absolute_path: '',
          resolved_path: '',
          candidates_checked: [],
          abspath: '',
          wp_plugin_dir: '',
          readable: false,
          file_size: 0,
          file_size_human: '0 B',
          error: 'پاسخ sandbox JSON نبود؛ جزئیات پاسخ خام را ببینید.',
          raw_response: rawResponse.slice(0, 1000),
          summary: {
            total_calls_found: 0,
            accepted: 0,
            skipped: 0,
            entries_unique: 0,
            by_function: {},
          },
          matches: [],
          accepted_msgids: [],
        };
      }
    }
  }

  const emptyResult = {
    input_path: inputPath,
    resolved_absolute_path: '',
    resolved_path: '',
    candidates_checked: [],
    abspath: '',
    wp_plugin_dir: '',
    readable: false,
    file_size: 0,
    file_size_human: '0 B',
    summary: {
      total_calls_found: 0,
      accepted: 0,
      skipped: 0,
      entries_unique: 0,
      by_function: {},
    },
    matches: [],
    accepted_msgids: [],
  };

  if (!payload || typeof payload !== 'object') {
    return {
      ...emptyResult,
      error: 'پاسخ sandbox نامعتبر بود.',
      raw_response: '',
    };
  }

  if (
    Object.prototype.hasOwnProperty.call(payload, 'readable') ||
    Object.prototype.hasOwnProperty.call(payload, 'summary') ||
    Object.prototype.hasOwnProperty.call(payload, 'resolved_absolute_path')
  ) {
    return {
      ...emptyResult,
      ...payload,
      input_path: payload.input_path || inputPath,
      candidates_checked: Array.isArray(payload.candidates_checked)
        ? payload.candidates_checked
        : [],
      summary: {
        ...emptyResult.summary,
        ...(payload.summary ?? {}),
      },
      matches: Array.isArray(payload.matches) ? payload.matches : [],
      accepted_msgids: Array.isArray(payload.accepted_msgids)
        ? payload.accepted_msgids
        : [],
      raw_response: '',
    };
  }

  const errorData = payload.data && typeof payload.data === 'object' ? payload.data : {};
  const message =
    payload.message ||
    errorData.message ||
    (payload.code ? `خطای وردپرس: ${payload.code}` : 'پاسخ sandbox نامعتبر بود.');

  return {
    ...emptyResult,
    error: message,
    code: payload.code || '',
    raw_response: '',
    resolved_absolute_path: errorData.resolved_absolute_path || '',
    resolved_path: errorData.resolved_path || '',
    candidates_checked: Array.isArray(errorData.candidates_checked)
      ? errorData.candidates_checked
      : [],
    abspath: errorData.abspath || errorData.debug_info?.abspath || '',
    wp_plugin_dir: errorData.wp_plugin_dir || errorData.debug_info?.wp_plugin_dir || '',
  };
}

function formatSkipReason(reason) {
  if (!reason) {
    return '';
  }

  const map = {
    empty_msgid: 'رشته خالی',
    missing_domain_arg: 'پارامتر text domain یافت نشد',
  };

  if (map[reason]) {
    return map[reason];
  }

  if (reason.startsWith('domain_literal_not_allowed:')) {
    return `text domain غیرمجاز: ${reason.replace('domain_literal_not_allowed:', '')}`;
  }

  return reason;
}

export default function UiStringsBulkTranslation() {
  const {
    langOptions,
    targetLang,
    setTargetLang,
    loading: langsLoading,
    error: langsError,
  } = useTargetLanguages('en');

  const [stats, setStats] = useState(null);
  const [job, setJob] = useState(null);
  const [loading, setLoading] = useState(true);
  const [scanning, setScanning] = useState(false);
  const [isRunning, setIsRunning] = useState(false);
  const [isPaused, setIsPaused] = useState(false);
  const [logs, setLogs] = useState([]);
  const [debugInfo, setDebugInfo] = useState(null);
  const [sandboxPath, setSandboxPath] = useState('');
  const [sandboxResult, setSandboxResult] = useState(null);
  const [sandboxLoading, setSandboxLoading] = useState(false);
  const [sandboxError, setSandboxError] = useState(null);

  const pauseRef = useRef(false);
  const stopRef = useRef(false);
  const runningRef = useRef(false);

  const appendLog = (message, type = 'info') => {
    setLogs((prev) => [...prev, { id: `${Date.now()}-${Math.random()}`, type, message }]);
  };

  const loadStats = useCallback(async () => {
    setLoading(true);

    try {
      const data = await fetchUiStringsStats(targetLang);
      setStats(data);
      setJob(data.job ?? null);
    } catch {
      appendLog('بارگذاری آمار رشته‌های UI ناموفق بود.', 'error');
    } finally {
      setLoading(false);
    }
  }, [targetLang]);

  useEffect(() => {
    loadStats();
  }, [loadStats]);

  const waitWhilePaused = async () => {
    while (pauseRef.current && !stopRef.current) {
      await new Promise((resolve) => setTimeout(resolve, 250));
    }
  };

  const runJobLoop = async () => {
    runningRef.current = true;

    while (!stopRef.current) {
      await waitWhilePaused();

      if (stopRef.current) {
        break;
      }

      try {
        const data = await uiStringsJobStep();
        setStats(data);
        setJob(data.job ?? data);

        const currentJob = data.job ?? data;
        const batchSaved = currentJob.batch_saved ?? 0;
        const batchSkipped = currentJob.batch_skipped ?? 0;

        if (batchSaved > 0) {
          appendLog(
            `دسته OK — ${currentJob.run_done ?? batchSaved} / ${currentJob.initial_total ?? currentJob.total ?? '?'} — ${currentJob.remaining ?? data.untranslated ?? 0} باقی‌مانده.`,
            'success'
          );
        } else if (batchSkipped > 0 || (currentJob.skipped ?? 0) > 0) {
          appendLog(
            `${batchSkipped || currentJob.skipped || 0} رشته رد شد — ${currentJob.remaining ?? data.untranslated ?? 0} باقی‌مانده.`,
            'info'
          );
        }

        if (currentJob.last_error) {
          if (currentJob.status === 'running' && (currentJob.remaining ?? data.untranslated ?? 0) > 0) {
            appendLog(`هشدار دسته: ${currentJob.last_error}`, 'info');
          } else {
            appendLog(currentJob.last_error, 'error');
            break;
          }
        }

        if (currentJob.status === 'completed' || (currentJob.remaining ?? data.untranslated ?? 0) <= 0) {
          appendLog('ترجمه گروهی رشته‌های UI به پایان رسید.', 'success');
          break;
        }

        if (currentJob.status !== 'running') {
          break;
        }
    } catch (error) {
      const message = extractApiErrorMessage(error);
      const raw = error?.response?.data;
      appendLog(`خطا در پردازش دسته: ${message}`, 'error');

      if (raw && typeof raw === 'object' && raw.job?.status === 'running') {
        appendLog('job هنوز running است — ادامه می‌دهیم…', 'info');
        continue;
      }

      break;
    }
    }

    runningRef.current = false;
    setIsRunning(false);
    setIsPaused(false);
    pauseRef.current = false;
    stopRef.current = false;
    await loadStats();
  };

  const handleScan = async () => {
    setScanning(true);
    setDebugInfo(null);

    try {
      const data = await scanUiStrings();
      setStats(data);
      setJob(data.job ?? null);
      setDebugInfo(extractDebugInfo(data));

      const pluginCount = Object.keys(data.plugins ?? {}).length;
      const stringCount = data.string_count ?? 0;

      if (stringCount <= 0) {
        appendLog('اسکن انجام شد اما رشته‌ای یافت نشد — جزئیات دیباگ را پایین ببینید.', 'error');
        return;
      }

      appendLog(`${stringCount} رشته از ${pluginCount} افزونه استخراج شد.`, 'success');
    } catch (error) {
      const message = extractApiErrorMessage(error);
      const debug = extractDebugInfo(error?.response?.data);
      setDebugInfo(debug);
      appendLog(message, 'error');

      if (debug) {
        appendLog(
          `دیباگ: ${debug.files_found_count ?? 0} فایل کاتالوگ، ${debug.total_source_entries_parsed ?? 0} رشته از PHP/JS، ${debug.scannable_plugin_slugs?.length ?? 0} افزونه اسکن‌شده.`,
          'info'
        );
      }
    } finally {
      setScanning(false);
    }
  };

  const handleSandboxTest = async () => {
    const path = sandboxPath.trim();

    if (!path) {
      setSandboxError('مسیر فایل را وارد کنید.');
      return;
    }

    setSandboxLoading(true);
    setSandboxError(null);
    setSandboxResult(null);

    try {
      const data = await sandboxTestUiStringFile(path);
      const result = normalizeSandboxResult(data, path);
      setSandboxResult(result);

      if (result.error) {
        setSandboxError(result.error);
      }
    } catch (error) {
      const result = normalizeSandboxResult(error?.response?.data, path);
      if (error?.response?.status && !result.http_status) {
        result.http_status = error.response.status;
      }
      setSandboxResult(result);
      setSandboxError(result.error || extractApiErrorMessage(error));
    } finally {
      setSandboxLoading(false);
    }
  };

  const handleStart = async () => {
    if (isRunning || runningRef.current) {
      return;
    }

    stopRef.current = false;
    pauseRef.current = false;
    setIsPaused(false);
    setIsRunning(true);
    setLogs([]);

    try {
      const data = await uiStringsJobAction('start', targetLang);
      setStats(data);
      setJob(data.job ?? null);
      appendLog(
        `ترجمه گروهی شروع شد — ${data.untranslated ?? 0} رشته در صف.`,
        'info'
      );
      await runJobLoop();
    } catch (error) {
      const message = extractApiErrorMessage(error);
      appendLog(message, 'error');
      setIsRunning(false);
    }
  };

  const handlePause = async () => {
    if (!isRunning) {
      return;
    }

    if (!pauseRef.current) {
      pauseRef.current = true;
      setIsPaused(true);
      await uiStringsJobAction('pause', targetLang);
      appendLog('ترجمه متوقف موقت شد.', 'info');
      return;
    }

    pauseRef.current = false;
    setIsPaused(false);
    await uiStringsJobAction('resume', targetLang);
    appendLog('ترجمه از سر گرفته شد.', 'info');
  };

  const handleStop = async () => {
    if (!isRunning) {
      return;
    }

    stopRef.current = true;
    pauseRef.current = false;
    setIsPaused(false);
    appendLog('درخواست توقف — در حال اتمام دسته جاری…', 'info');

    try {
      await uiStringsJobAction('stop', targetLang);
    } catch {
      // Loop will exit regardless.
    }
  };

  const runTotal = job?.initial_total ?? job?.total ?? stats?.untranslated ?? 0;
  const runRemaining = stats?.untranslated ?? job?.run_remaining ?? job?.remaining ?? 0;
  const runDone =
    job?.run_done ??
    Math.max(0, Math.min(runTotal, runTotal - runRemaining));
  const progressPercent =
    job?.progress_percent ??
    (runTotal > 0 ? Math.min(100, Math.round((runDone / runTotal) * 100)) : 0);
  const catalogTotal = job?.catalog_total ?? stats?.string_count ?? 0;
  const pluginCount = stats?.plugins ? Object.keys(stats.plugins).length : 0;

  return (
    <section aria-labelledby="ui-strings-heading">
      <h2 id="ui-strings-heading" className="sr-only">
        ترجمه گروهی رشته‌های UI
      </h2>

      <div className="rounded border border-pmai-border bg-pmai-surface p-4">
        <p className="mt-0 text-sm text-pmai-muted">
          رشته‌های gettext از کد PHP/JS افزونه‌ها (توابع __(), _e(), _x(), wp.i18n و…) استخراج می‌شوند.
          text domain می‌تواند رشته کوتیشن‌دار ('polymart-ai') یا ثابت PHP (مثل APD_TEXT_DOMAIN) باشد.
          ترجمه فقط در ادمین انجام و در دیتابیس ذخیره می‌شود.
        </p>

        <div className="mt-4 flex flex-wrap items-end gap-4">
          {langsError && (
            <div className="w-full">
              <Notice type="warning" message={langsError} />
            </div>
          )}

          <div>
            <label htmlFor="ui-strings-target-lang" className="mb-1 block text-xs font-medium text-gray-700">
              زبان مقصد
            </label>
            <LanguageSelect
              id="ui-strings-target-lang"
              value={targetLang}
              onChange={setTargetLang}
              options={langOptions}
              loading={langsLoading}
              disabled={isRunning}
            />
          </div>

          <div className="rounded border border-pmai-border bg-gray-50 px-4 py-3">
            <p className="text-xs uppercase tracking-wide text-pmai-muted">کل رشته‌ها</p>
            <p className="text-2xl font-semibold text-gray-900">
              {loading ? '…' : stats?.string_count ?? 0}
            </p>
          </div>

          <div className="rounded border border-pmai-border bg-gray-50 px-4 py-3">
            <p className="text-xs uppercase tracking-wide text-pmai-muted">ترجمه‌شده</p>
            <p className="text-2xl font-semibold text-green-700">
              {loading ? '…' : stats?.translated ?? job?.translated ?? 0}
            </p>
          </div>

          <div className="rounded border border-pmai-border bg-gray-50 px-4 py-3">
            <p className="text-xs uppercase tracking-wide text-pmai-muted">نیاز به ترجمه</p>
            <p className="text-2xl font-semibold text-amber-700">
              {loading ? '…' : stats?.untranslated ?? runRemaining}
            </p>
          </div>

          <div className="rounded border border-pmai-border bg-gray-50 px-4 py-3">
            <p className="text-xs uppercase tracking-wide text-pmai-muted">آخرین اسکن</p>
            <p className="text-sm font-medium text-gray-900">
              {loading ? '…' : formatScannedAt(stats?.scanned_at)}
            </p>
            <p className="text-xs text-pmai-muted">{pluginCount} افزونه</p>
          </div>

          <button
            type="button"
            onClick={handleScan}
            disabled={scanning || isRunning}
            className="rounded border border-pmai-border bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {scanning ? 'در حال اسکن…' : 'اسکن رشته‌ها از کد افزونه‌ها'}
          </button>

          <button
            type="button"
            onClick={loadStats}
            disabled={loading || isRunning}
            className="rounded border border-pmai-border bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
          >
            به‌روزرسانی
          </button>
        </div>

        {stats && (
          <div className="mt-4 rounded border border-pmai-border bg-gray-50 px-4 py-3 text-xs text-gray-700">
            <p>
              وضعیت API:{' '}
              <strong className={stats.ai_configured ? 'text-green-700' : 'text-red-700'}>
                {stats.ai_configured ? 'پیکربندی شده' : 'نیاز به تنظیم (فقط برای ترجمه گروهی)'}
              </strong>
            </p>
            <p className="mt-1 text-pmai-muted">
              <strong>اسکن</strong> یعنی خواندن فایل‌های PHP/JS افزونه‌ها و پیدا کردن متن‌های قابل ترجمه — بدون
              مصرف API. «ترجمه گروهی UI» همان رشته‌ها را با هوش مصنوعی ترجمه می‌کند و به کلید API و آدرس Gateway
              نیاز دارد.
            </p>
          </div>
        )}

        {stats?.plugins && pluginCount > 0 && (
          <div className="mt-4 rounded border border-pmai-border bg-gray-50 p-3 text-xs text-gray-700">
            <p className="mb-2 font-medium text-gray-900">افزونه‌های اسکن‌شده</p>
            <ul className="grid gap-1 sm:grid-cols-2">
              {Object.entries(stats.plugins).map(([slug, info]) => (
                <li key={slug}>
                  <span className="font-mono">{slug}</span>
                  {' — '}
                  {info.count ?? 0} رشته
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="mt-6 flex flex-wrap gap-3">
          <button
            type="button"
            onClick={handleStart}
            disabled={loading || isRunning || (stats?.untranslated ?? 0) <= 0}
            className="rounded bg-pmai-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-pmai-primary-dark disabled:cursor-not-allowed disabled:opacity-60"
          >
            شروع ترجمه گروهی UI
          </button>

          <button
            type="button"
            onClick={handlePause}
            disabled={!isRunning}
            className="rounded border border-pmai-border bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isPaused ? 'ادامه' : 'توقف موقت'}
          </button>

          <button
            type="button"
            onClick={handleStop}
            disabled={!isRunning}
            className="rounded border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
          >
            توقف
          </button>
        </div>

        {(isRunning || progressPercent > 0 || job?.status === 'completed') && runTotal > 0 && (
          <div className="mt-6">
            <div className="mb-3 grid gap-3 sm:grid-cols-4">
              {[
                { label: 'پیشرفت این اجرا', value: `${runDone} / ${runTotal}` },
                { label: 'باقی در صف', value: runRemaining },
                { label: 'موفق', value: runDone, color: 'text-green-700' },
                { label: 'خطا', value: job?.failed ?? 0, color: 'text-red-700' },
              ].map((item) => (
                <div key={item.label} className="rounded-lg border border-pmai-border bg-gray-50 px-3 py-2">
                  <p className="text-xs text-pmai-muted">{item.label}</p>
                  <p className={`text-lg font-bold ${item.color ?? ''}`}>{item.value}</p>
                </div>
              ))}
            </div>
            <div className="mb-2 flex items-center justify-between text-sm text-pmai-muted">
              <span>پیشرفت این اجرا</span>
              <span>
                {runDone} / {runTotal} ({progressPercent}%)
              </span>
            </div>
            <div className="h-3 w-full overflow-hidden rounded-full bg-gray-200">
              <div
                className="h-full rounded-full bg-pmai-primary transition-all duration-300"
                style={{ width: `${progressPercent}%` }}
              />
            </div>
            <p className="mt-2 text-xs text-pmai-muted">
              کل کاتالوگ: {catalogTotal} رشته — این اجرا فقط {runTotal} رشته ترجمه‌نشده در شروع را هدف
              می‌گیرد.
            </p>
          </div>
        )}

        <div className="mt-6">
          <label className="mb-2 block text-sm font-medium text-gray-900">گزارش فعالیت</label>
          <div className="max-h-64 overflow-y-auto rounded border border-pmai-border bg-gray-50 p-3 font-mono text-xs leading-relaxed">
            {logs.length === 0 ? (
              <p className="text-pmai-muted">
                ابتدا «اسکن رشته‌ها از کد افزونه‌ها» را بزنید، سپس ترجمه گروهی را شروع کنید.
              </p>
            ) : (
              logs.map((entry) => (
                <p
                  key={entry.id}
                  className={
                    entry.type === 'success'
                      ? 'text-green-800'
                      : entry.type === 'error'
                        ? 'text-red-700'
                        : 'text-gray-700'
                  }
                >
                  {entry.message}
                </p>
              ))
            )}
          </div>
        </div>

        {devMode && debugInfo && (
          <div className="mt-6">
            <label className="mb-2 block text-sm font-medium text-gray-900">
              اطلاعات دیباگ اسکن
            </label>
            <div className="max-h-96 overflow-y-auto rounded border border-amber-200 bg-amber-50 p-3 font-mono text-xs leading-relaxed text-gray-800">
              <p>WP_PLUGIN_DIR: {debugInfo.wp_plugin_dir}</p>
              <p className="mt-2">
                فایل‌های یافت‌شده: {debugInfo.files_found_count ?? 0} — parse شده:{' '}
                {debugInfo.total_entries_parsed ?? 0} — اضافه‌شده:{' '}
                {debugInfo.total_entries_added ?? 0} — یکتا:{' '}
                {debugInfo.total_unique_strings ?? 0}
              </p>

              {Array.isArray(debugInfo.searched_paths) && debugInfo.searched_paths.length > 0 && (
                <div className="mt-3">
                  <p className="font-semibold">مسیرهای جستجو:</p>
                  <ul className="mt-1 list-inside list-disc">
                    {[...new Set(debugInfo.searched_paths)].map((path) => (
                      <li key={path} dir="ltr">
                        {path}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {Array.isArray(debugInfo.files_found) && debugInfo.files_found.length > 0 && (
                <div className="mt-3">
                  <p className="font-semibold">فایل‌های پیدا شده:</p>
                  <ul className="mt-1 list-inside list-disc">
                    {debugInfo.files_found.map((path) => (
                      <li key={path} dir="ltr">
                        {path}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {Array.isArray(debugInfo.plugins) && debugInfo.plugins.length > 0 && (
                <div className="mt-3">
                  <p className="font-semibold">جزئیات هر افزونه:</p>
                  {debugInfo.plugins.map((plugin) => (
                    <div key={plugin.slug} className="mt-2 rounded border border-amber-100 bg-white p-2">
                      <p>
                        <span className="font-semibold">{plugin.slug}</span>
                        {plugin.dir_exists ? '' : ' — پوشه پیدا نشد'}
                      </p>
                      {plugin.resolved_dir && (
                        <p dir="ltr" className="text-pmai-muted">
                          {plugin.resolved_dir}
                        </p>
                      )}
                      {Array.isArray(plugin.files_parsed) &&
                        plugin.files_parsed.map((file) => (
                          <p key={file.path} className="mt-1" dir="ltr">
                            {file.path}
                            {' → '}
                            parsed: {file.entries_parsed ?? 0}, added: {file.entries_added ?? 0}
                            {file.skipped_reason ? ` (${file.skipped_reason})` : ''}
                            {file.parse_error ? ` — ${file.parse_error}` : ''}
                          </p>
                        ))}
                      {(plugin.source_files_scanned ?? 0) > 0 && (
                        <p className="mt-1">
                          source scan: {plugin.source_files_scanned} فایل PHP/JS —{' '}
                          {plugin.source_entries_added ?? 0} رشته اضافه شد
                        </p>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      {devMode && (
      <div className="mt-8 rounded border border-indigo-200 bg-indigo-50/40 p-4">
        <h3 className="text-sm font-semibold text-gray-900">تست مستقیم یک فایل (Sandbox Tester)</h3>
        <p className="mt-1 text-xs text-pmai-muted">
          مسیر نسبی یا کامل یک فایل PHP/JS داخل wp-content/plugins را وارد کنید — مثلاً{' '}
          <code className="rounded bg-white px-1" dir="ltr">
            wp-content/plugins/Advanced-Product-Description/admin/class-apd-admin.php
          </code>
        </p>

        <div className="mt-3 flex flex-wrap gap-3">
          <input
            type="text"
            value={sandboxPath}
            onChange={(e) => setSandboxPath(e.target.value)}
            placeholder="wp-content/plugins/your-plugin/file.php"
            className="min-w-[16rem] flex-1 rounded-lg border border-pmai-border bg-white px-3 py-2 font-mono text-xs"
            dir="ltr"
            disabled={sandboxLoading}
          />
          <button
            type="button"
            onClick={handleSandboxTest}
            disabled={sandboxLoading || !sandboxPath.trim()}
            className="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm font-medium text-indigo-800 transition hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {sandboxLoading ? 'در حال آنالیز…' : 'تست و آنالیز فایل'}
          </button>
        </div>

        {sandboxError && (
          <p className="mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
            {sandboxError}
          </p>
        )}

        {sandboxResult && (
          <div className="mt-4 space-y-4 rounded border border-indigo-100 bg-white p-3 text-xs text-gray-800">
            <div className="grid gap-2 sm:grid-cols-2">
              <p>
                <strong>خوانده شد:</strong> {sandboxResult.readable ? 'بله' : 'خیر'}
              </p>
              <p>
                <strong>حجم فایل:</strong> {sandboxResult.file_size_human ?? '—'} (
                {sandboxResult.file_size ?? 0} بایت)
              </p>
              <p className="sm:col-span-2" dir="ltr">
                <strong>مسیر ورودی:</strong> {sandboxResult.input_path || sandboxPath.trim() || '—'}
              </p>
              <p className="sm:col-span-2" dir="ltr">
                <strong>مسیر مطلق بررسی‌شده (Resolved Absolute Path):</strong>{' '}
                {sandboxResult.resolved_absolute_path || sandboxResult.resolved_path || '—'}
              </p>
              <p dir="ltr">
                <strong>ABSPATH:</strong> {sandboxResult.abspath || '—'}
              </p>
              <p dir="ltr">
                <strong>WP_PLUGIN_DIR:</strong> {sandboxResult.wp_plugin_dir || '—'}
              </p>
              <p>
                <strong>کل فراخوانی‌های i18n:</strong> {sandboxResult.summary?.total_calls_found ?? 0}
              </p>
              <p>
                <strong>پذیرفته‌شده / ردشده:</strong> {sandboxResult.summary?.accepted ?? 0} /{' '}
                {sandboxResult.summary?.skipped ?? 0}
              </p>
            </div>

            {Array.isArray(sandboxResult.candidates_checked) &&
              sandboxResult.candidates_checked.length > 0 && (
                <div>
                  <p className="mb-1 font-semibold">مسیرهای امتحان‌شده:</p>
                  <ul className="max-h-32 list-inside list-disc overflow-y-auto font-mono" dir="ltr">
                    {sandboxResult.candidates_checked.map((candidate) => (
                      <li key={candidate}>{candidate}</li>
                    ))}
                  </ul>
                </div>
              )}

            {sandboxResult.raw_response && (
              <div>
                <p className="mb-1 font-semibold">پاسخ خام سرور:</p>
                <pre className="max-h-48 overflow-y-auto whitespace-pre-wrap rounded border border-red-100 bg-red-50 p-2 text-[11px] text-red-900" dir="ltr">
                  {sandboxResult.http_status ? `HTTP ${sandboxResult.http_status}\n` : ''}
                  {sandboxResult.raw_response}
                </pre>
              </div>
            )}

            {sandboxResult.summary?.by_function &&
              Object.keys(sandboxResult.summary.by_function).length > 0 && (
                <div>
                  <p className="mb-1 font-semibold">تعداد به تفکیک تابع:</p>
                  <ul className="list-inside list-disc font-mono" dir="ltr">
                    {Object.entries(sandboxResult.summary.by_function).map(([fn, count]) => (
                      <li key={fn}>
                        {fn}(): {count}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

            {Array.isArray(sandboxResult.accepted_msgids) &&
              sandboxResult.accepted_msgids.length > 0 && (
                <div>
                  <p className="mb-1 font-semibold">رشته‌های پذیرفته‌شده ({sandboxResult.accepted_msgids.length}):</p>
                  <ul className="max-h-40 list-inside list-disc overflow-y-auto">
                    {sandboxResult.accepted_msgids.map((msgid) => (
                      <li key={msgid}>{msgid}</li>
                    ))}
                  </ul>
                </div>
              )}

            {Array.isArray(sandboxResult.matches) && sandboxResult.matches.length > 0 && (
              <div>
                <p className="mb-2 font-semibold">جزئیات خام هر فراخوانی:</p>
                <div className="max-h-96 space-y-2 overflow-y-auto rounded border border-gray-100 bg-gray-50 p-2">
                  {sandboxResult.matches.map((match, index) => (
                    <div
                      key={`${match.line}-${match.function}-${index}`}
                      className={`rounded border px-2 py-1.5 ${
                        match.status === 'accepted'
                          ? 'border-green-200 bg-green-50'
                          : 'border-amber-200 bg-amber-50'
                      }`}
                    >
                      <p dir="ltr" className="font-mono text-[11px] text-gray-600">
                        L{match.line} · {match.function}() · domain={match.domain_arg || '—'} (
                        {match.domain_type})
                        {match.domain_resolved ? ` → ${match.domain_resolved}` : ''}
                      </p>
                      <p className="mt-0.5 font-medium text-gray-900">{match.msgid || '(خالی)'}</p>
                      {match.context ? (
                        <p className="text-pmai-muted">context: {match.context}</p>
                      ) : null}
                      {match.status === 'skipped' && match.skip_reason ? (
                        <p className="mt-0.5 text-red-700">
                          Skip: {formatSkipReason(match.skip_reason)}
                        </p>
                      ) : null}
                      {match.snippet ? (
                        <p className="mt-0.5 font-mono text-[10px] text-gray-500" dir="ltr">
                          {match.snippet}
                        </p>
                      ) : null}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
      )}
    </section>
  );
}
