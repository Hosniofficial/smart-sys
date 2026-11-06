import { defineStore } from 'pinia';
import { rbacService } from '@/services/rbac';

const getData = (response) => response?.data?.data;

export const useRbacStore = defineStore('rbac', () => {
  const listUsers = async (params = {}) => {
    const response = await rbacService.listUsers(params);
    const data = getData(response);
    if (data && typeof data === 'object' && Array.isArray(data.items)) {
      const meta = response?.data?.meta || {};
      return {
        items: data.items,
        total: Number(meta.total) || data.items.length,
        page: Number(meta.page) || Number(params.page) || 1,
        limit: Number(meta.limit) || Number(params.limit) || data.items.length,
        totalPages: Number(meta.totalPages) || 1,
      };
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
    if (!Array.isArray(data?.permissions) || !Array.isArray(data?.basePermissions)) {
      throw new Error('استجابة صلاحيات الدور غير صالحة.');
    }
    return data;
  };

  const updateRolePermissions = async (roleId, payload) =>
    rbacService.updateRolePermissions(roleId, payload);
  const createRole = async (payload) => rbacService.createRole(payload);
  const updateRole = async (roleId, payload) => rbacService.updateRole(roleId, payload);
  const deleteRole = async (roleId, payload) => rbacService.deleteRole(roleId, payload);

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
    createRole,
    updateRole,
    deleteRole,
  };
});
