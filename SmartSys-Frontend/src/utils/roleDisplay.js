export function getRoleCode(role) {
  if (!role) return ''
  return String(role.roleCode || role.code || role.name || role.role || '').trim()
}

export function getRoleDisplayName(role) {
  if (!role) return ''
  return String(role.displayName || role.roleDisplayName || role.name || role.role || role.roleCode || '').trim()
}
