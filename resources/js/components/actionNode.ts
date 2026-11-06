import type { SchemaNode } from '@xelqun/forms';
import type { TableAction } from '../schema/types';

/** XqAction reads a form node, and an action's key is its name here. */
export function actionNode(action: TableAction): SchemaNode {
    return {
        key: action.name,
        type: action.actions ? 'action-group' : 'action',
        hidden: false,
        props: action.props,
        children: action.actions?.map(actionNode) ?? [],
        deps: [],
    };
}
