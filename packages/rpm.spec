Summary: {{ DESC }}
Name: {{ NAME }}
Version: {{ VERSION }}
Release: 1%{?dist}
Group: Applications
License: GPLv2
Packager: {{ MAINTAINER }}
Vendor: {{ MAINTAINER }}
Requires: {{ LIBCURL_NAME }} >= {{ LIBCURL_VERSION }}

Source: tmp.tar.gz
BuildRoot: %{_tmppath}/%{name}-%{version}-buildroot
BuildArch: noarch

%description
{{ DESC }}

%prep
rm -rf $RPM_BUILD_ROOT

%setup -n %{name}

%build

%install
mkdir -p $RPM_BUILD_ROOT
cp -p usr/share/manticore/modules/manticore-buddy $RPM_BUILD_ROOT/

%clean
rm -rf $RPM_BUILD_ROOT

%post

%postun

%files
%defattr(-, root, root)
/manticore-buddy

%changelog
