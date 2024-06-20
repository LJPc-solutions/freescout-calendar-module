import { h } from 'preact';

import { useDispatch, useStore } from '@src/contexts/calendarStore';
import { cls } from '@src/helpers/css';
import type { CalendarState } from '@src/types/store';
import { isPresent } from '@src/utils/type';

function shownPopupParamSelector(state: CalendarState) {
  return Object.values(state.popup).find((popup) => isPresent(popup));
}

export function PopupOverlay() {
  const shownPopupParam = useStore(shownPopupParamSelector);
  const { hideAllPopup } = useDispatch('popup');

  const isPopupShown = isPresent(shownPopupParam);

  const onClick = (ev: MouseEvent) => {
    ev.stopPropagation();

    shownPopupParam?.close?.();
    hideAllPopup();
  };

  return (
    <div
      className={cls('popup-overlay')}
      style={{ display: isPopupShown ? 'block' : 'none' }}
      onClick={onClick}
    />
  );
}
