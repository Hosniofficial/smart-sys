import { defineStore } from 'pinia';
import { rbacService } from '@/services/rbac';

const getData = (response) => response?.data?.data ?? response?.data;

export const useRbacStore = defineStore('rbac', () => {
  const listUsers = async (params = {}) => {
    const data = getData(await rbacService.listUsers(params));
    // إذا كان الـ response يحتوي على items (pagination response)
    if (data && typeof data === 'object' && Array.isArray(data.items)) {
      return data; // {items, total, page, limit, total_pages}
    }
    // للتوافق مع الإصدارات القديمة (array مباشرة)
    if (Array.isArray(data)) {
      return { items: data, total: data.length, page: 1, limit: data.length, total_pages: 1 };
    }
    throw new Error('استجابة قائمة المستخدمين غير صالحة.');
  };

  const createUser = async (payload) => rbacService.createUser(payload);
  const updateUser = async (userId, payload) => rbacService.updateUser(userId, payload);
  const deleteUser = async (userId) => rbacService.deleteUser(userId);

  const getUserPermissions = async (userId) => {
    const data = getData(await rbacService.getUserPermissions(userId));
    if (!Array.isArray(data?.permissions)) {
      throw new Error('استجابة صلاحيات المستخدم غير صالحة.');
    }
    return data;
  };

  const updateUserPermissions = async (userId, payload) =>
    rbacService.updateUserPermissions(userId, payload);

  const listPermissions = async () => {
    const data = getData(await rbacService.listPermissions());
    if (!Array.isArray(data)) throw new Error('استجابة قائمة الصلاحيات غير صالحة.');
    return data;
  };

  const getRolePermissions = async (roleId) => {
    const data = getData(await rbacService.getRolePermissions(roleId));
    if (!Array.isArray(data?.permissions) || !Array.isArray(data?.base_permissions)) {
      throw new Error('استجابة صلاحيات الدور غير صالحة.');
    }
    return data;
  };

  const updateRolePermissions = async (roleId, payload) =>
    rbacService.updateRolePermissions(roleId, payload);

  return {
    listUsers,
    createUser,
    updateUser,
    deleteUser,
    getUserPermissions,
    updateUserPermissions,
    listPermissions,
    getRolePermissions,
    updateRolePermissions,
  };
});
