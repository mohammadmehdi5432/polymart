import { Component } from 'react';

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-red-900">
          <h2 className="text-lg font-semibold">خطا در بارگذاری پنل</h2>
          <p className="mt-2 text-sm">
            {this.state.error?.message || 'یک خطای غیرمنتظره رخ داد. صفحه را رفرش کنید.'}
          </p>
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="mt-4 rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800"
          >
            بارگذاری مجدد
          </button>
        </div>
      );
    }

    return this.props.children;
  }
}
