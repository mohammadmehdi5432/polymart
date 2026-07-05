import { useCallback, useEffect, useState } from 'react';

export function useHashTab(defaultTab, validTabIds) {
  const validTabs = new Set(validTabIds);

  const readHash = () => {
    const hash = window.location.hash.replace(/^#/, '');
    return validTabs.has(hash) ? hash : defaultTab;
  };

  const [activeTab, setActiveTabState] = useState(readHash);

  useEffect(() => {
    const onHashChange = () => {
      setActiveTabState(readHash());
    };

    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  }, []);

  const setActiveTab = useCallback(
    (tab) => {
      if (!validTabs.has(tab)) {
        return;
      }

      setActiveTabState(tab);

      const nextHash = `#${tab}`;
      if (window.location.hash !== nextHash) {
        window.history.replaceState(null, '', nextHash);
      }
    },
    [validTabs]
  );

  useEffect(() => {
    if (!window.location.hash) {
      window.history.replaceState(null, '', `#${defaultTab}`);
    }
  }, [defaultTab]);

  return [activeTab, setActiveTab];
}
